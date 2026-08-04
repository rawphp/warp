<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

use Closure;
use RawPHP\Warp\Support\AtomicFile;
use RawPHP\Warp\Support\Dirs;
use RawPHP\Warp\Support\FileLock;
use RawPHP\Warp\Support\Paths;
use RawPHP\Warp\Support\Stderr;
use RuntimeException;

final class TimingStore
{
    /** Bump to discard every stored timing when the on-disk format changes. */
    private const VERSION = 3;

    /**
     * One read pass over pending/ + timings.json, computed at most once per
     * instance and shared by load(), storedRoot(), and fileTotals() so a
     * single command invocation scans pending/ and parses every batch
     * exactly once (finding 17), and the three can never observe different
     * store states - the TOCTOU half of finding 2/17. null means "not yet
     * computed"; a genuinely empty store still caches an array.
     *
     * @var array{tests: array<string, array{file: string, ms: float}>, root: string|null}|null
     */
    private ?array $snapshotCache = null;

    private readonly string $dir;

    /**
     * @param  string  $dir  Absolutized here, in the constructor, against the
     *                       construction-time cwd via Paths::absolute()
     *                       (REQ-107, finding 10) - so every entry point
     *                       (fromEnv(), a raw `new TimingStore($dir)` from
     *                       TimingStoreArgumentParser, withRoot()/withWarner()'s
     *                       reconstruction) resolves a relative dir identically,
     *                       and a later chdir() can never move it.
     * @param  Closure(string): void|null  $warn  Warning sink for non-fatal diagnostics.
     *                                            CLI commands inject one that writes to their captured $stderr stream so an
     *                                            embedded WarpCli::run never leaks onto the host process's real STDERR; the
     *                                            PHPUnit-extension/embedded default (null) falls back to process STDERR.
     */
    public function __construct(
        string $dir,
        private readonly ?string $root = null,
        private readonly ?Closure $warn = null,
    ) {
        $this->dir = Paths::absolute($dir, getcwd() ?: '.');
    }

    public static function fromEnv(): self
    {
        $dir = getenv('WARP_TIMINGS_DIR');

        return new self($dir !== false && $dir !== '' ? $dir : '.warp/timings');
    }

    /**
     * Bind the canonical timing-key root stamped into every batch this store writes.
     * The root is the phpunit config file's directory (see TimingExtension).
     */
    public function withRoot(?string $root): self
    {
        return new self($this->dir, $root, $this->warn);
    }

    /**
     * Bind the warning sink for this store's non-fatal diagnostics. CLI commands
     * pass a sink that writes to their injected $stderr stream; null restores the
     * process-STDERR default used by the PHPUnit extension.
     *
     * @param  Closure(string): void|null  $warn
     */
    public function withWarner(?Closure $warn): self
    {
        return new self($this->dir, $this->root, $warn);
    }

    /**
     * Route a non-fatal warning to the injected sink, or process STDERR when none
     * was bound. Every store warning reachable from a CLI command flows through
     * the injected stream; only the extension/embedded default hits raw STDERR.
     */
    private function warn(string $message): void
    {
        if ($this->warn !== null) {
            ($this->warn)($message);

            return;
        }

        Stderr::write($message);
    }

    /**
     * Lock-free per-process batch: unique filename, atomic tmp+rename publish.
     * `$completeFiles` maps each file this process fully accounted for to whether
     * every enumerated test terminated; `apply()` supersedes only complete files.
     *
     * A run whose tests all skipped (or all errored before preparation) produces
     * an empty `$tests` with a non-empty `$completeFiles` - there is still
     * something to say (the file is complete, its stale entries must go), so the
     * write only skips when BOTH are empty (finding 6). Discarding a batch that
     * carries only completeness silently loses the supersede signal and lets a
     * fully-skipped file's stale timings persist forever.
     *
     * @param  array<string, array{file: string, ms: float}>  $tests
     * @param  array<string, bool>  $completeFiles
     */
    public function writePending(array $tests, array $completeFiles = []): void
    {
        if ($tests === [] && $completeFiles === []) {
            return;
        }

        Dirs::ensure($this->dir.'/pending');

        $path = $this->dir.'/pending/'.PendingBatches::nextTimestamp().'-'.getmypid().'-'.bin2hex(random_bytes(4)).'.json';

        $encoded = json_encode(['complete' => $completeFiles, 'root' => $this->root, 'tests' => $tests], JSON_THROW_ON_ERROR);
        AtomicFile::write(
            $path,
            $encoded,
            '[warp] cannot write pending timings batch',
            '[warp] cannot publish pending timings batch',
        );
    }

    public function mergeToDisk(): int
    {
        if (! is_dir($this->dir.'/pending')) {
            return 0;
        }

        return FileLock::withLock($this->dir.'/merge.lock', function (): int {
            $pending = $this->pendingBatches();
            $paths = $pending->paths();

            if ($paths === []) {
                return 0;
            }

            [$tests, $mergedPending, $root] = $this->mergedWithPending($pending, $paths, true);

            AtomicFile::write(
                $this->dir.'/timings.json',
                json_encode(['version' => self::VERSION, 'root' => $root, 'tests' => $tests], JSON_THROW_ON_ERROR),
                '[warp] cannot write merged timings',
                '[warp] cannot publish merged timings',
            );

            foreach ($mergedPending as $path) {
                if (! @unlink($path)) {
                    $this->warn('[warp] cannot delete merged pending timings batch at '.$path.PHP_EOL);
                }
            }

            return count($mergedPending);
        });
    }

    /** @return array<string, array{file: string, ms: float}> */
    public function load(): array
    {
        return $this->snapshot()['tests'];
    }

    /**
     * The canonical timing-key root stamped into the artifact (merged plus any
     * pending overlay), or null when timings were recorded without one.
     */
    public function storedRoot(): ?string
    {
        return $this->snapshot()['root'];
    }

    /** @return array<string, float> file => total ms, path-sorted */
    public function fileTotals(): array
    {
        return TimingsMerge::aggregate($this->snapshot()['tests']);
    }

    /**
     * @return array{tests: array<string, array{file: string, ms: float}>, root: string|null}
     */
    private function snapshot(): array
    {
        return $this->snapshotCache ??= $this->readSnapshot();
    }

    /**
     * @return array{tests: array<string, array{file: string, ms: float}>, root: string|null}
     */
    private function readSnapshot(): array
    {
        if (! is_dir($this->dir.'/pending')) {
            $merged = $this->readMergedData();

            return ['tests' => $merged['tests'], 'root' => $merged['root']];
        }

        $read = function (): array {
            $pending = $this->pendingBatches();
            [$tests, , $root] = $this->mergedWithPending($pending, $pending->paths());

            return ['tests' => $tests, 'root' => $root];
        };

        // Hold merge.lock across the whole read so a concurrent `warp merge`
        // cannot publish timings.json mid-scan (finding 2): without it, a
        // shard invocation could see pre-merge pending batches for one part
        // of the snapshot and post-merge timings.json for another, producing
        // a divergent LPT plan. When the lock file itself cannot be created
        // - a read-only timings dir restored from a CI cache (UR-011
        // guarantee) - fall back to today's lockless read with its existing
        // vanished-batch tolerance. The read path never creates or modifies
        // files besides this lock attempt.
        return FileLock::withLockOr(
            $this->dir.'/merge.lock',
            $read,
            $read,
        );
    }

    private function pendingBatches(): PendingBatches
    {
        return new PendingBatches($this->dir.'/pending', $this->warn(...));
    }

    /**
     * @param  list<string>  $paths
     * @return array{0: array<string, array{file: string, ms: float}>, 1: list<string>, 2: string|null}
     */
    private function mergedWithPending(PendingBatches $pending, array $paths, bool $cleanupJunk = false): array
    {
        $merged = $this->readMergedData();
        // The authoritative root is the existing artifact's root; when no artifact
        // has established one yet, the first pending batch to carry a root wins.
        // Every later batch whose root differs is foreign and never allowed to flip
        // the stored root or mix key domains (finding 3).
        $rootEstablished = is_file($this->dir.'/timings.json') && $merged['root'] !== null;

        return $pending->fold(
            $merged['tests'],
            $merged['root'],
            $rootEstablished,
            $paths,
            $cleanupJunk,
        );
    }

    /** @return array{root: string|null, tests: array<string, array{file: string, ms: float}>} */
    private function readMergedData(): array
    {
        if (! is_file($this->dir.'/timings.json')) {
            return ['root' => null, 'tests' => []];
        }

        $contents = file_get_contents($this->dir.'/timings.json');

        if ($contents === false) {
            throw new RuntimeException('[warp] cannot read timings from '.$this->dir.'/timings.json');
        }

        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // A corrupt/truncated artifact (e.g. a partial CI cache restore) must never
            // hard-fail the shard matrix: degrade to empty with a warning, consistent
            // with the missing-file and wrong-version paths. Sharding falls back to
            // count-balanced; `warp timings` reports nothing recorded.
            $this->warn('[warp] cannot decode timings from '.$this->dir.'/timings.json: '.json_last_error_msg().' - sharding count-balanced'.PHP_EOL);

            return ['root' => null, 'tests' => []];
        }

        if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION || ! is_array($data['tests'] ?? null)) {
            return ['root' => null, 'tests' => []];
        }

        $tests = TimingsMerge::sanitizeTests($data['tests']);

        return [
            'root' => isset($data['root']) && is_string($data['root']) ? $data['root'] : null,
            'tests' => $tests,
        ];
    }
}
