<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

use Closure;

/**
 * Pending-batch directory: list ordered batch paths and fold them onto a
 * timings document. No lock ownership and no timings.json I/O — those stay on
 * {@see TimingStore}.
 *
 * @internal Package timing-store plumbing; not host-facing.
 */
final class PendingBatches
{
    private static int $lastTimestamp = 0;

    /**
     * @param  Closure(string): void  $warn
     */
    public function __construct(
        private readonly string $dir,
        private readonly Closure $warn,
    ) {}

    /**
     * Monotonic microsecond timestamp for a new pending batch filename.
     * Process-wide so two writePending calls in the same microsecond never
     * collide on the same basename.
     */
    public static function nextTimestamp(): int
    {
        $timestamp = (int) floor(microtime(true) * 1_000_000);

        if ($timestamp <= self::$lastTimestamp) {
            $timestamp = self::$lastTimestamp + 1;
        }

        self::$lastTimestamp = $timestamp;

        return $timestamp;
    }

    /**
     * Ordered list of readable-looking pending batch paths (timestamp ASC, path ASC).
     *
     * @return list<string>
     */
    public function paths(): array
    {
        // @-suppressed: pending/ can vanish or become unreadable between the
        // is_dir() check in TimingStore::readSnapshot() and this scandir() call
        // (finding 11). A failure degrades to "no pending batches found" below;
        // suppression only silences PHP's native diagnostic so it never leaks
        // onto the process's real STDERR, bypassing the injected warn sink.
        $entries = @scandir($this->dir);

        if ($entries === false) {
            return [];
        }

        $files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ! str_ends_with($entry, '.json')) {
                continue;
            }

            $path = $this->dir.'/'.$entry;

            if (! is_file($path)) {
                continue;
            }

            if (! preg_match('/^(\d+)-\d+-[a-f0-9]{8}\.json$/', $entry, $matches)) {
                ($this->warn)('[warp] skipped old-format pending timings batch: '.$path.PHP_EOL);

                continue;
            }

            $files[] = ['path' => $path, 'timestamp' => (int) $matches[1]];
        }

        usort($files, static function (array $a, array $b): int {
            return $a['timestamp'] <=> $b['timestamp']
                ?: $a['path'] <=> $b['path'];
        });

        return array_column($files, 'path');
    }

    /**
     * Fold ordered pending batch paths onto an existing tests map / root.
     *
     * @param  array<string, array{file: string, ms: float}>  $tests
     * @param  list<string>  $pending
     * @return array{0: array<string, array{file: string, ms: float}>, 1: list<string>, 2: string|null}
     */
    public function fold(
        array $tests,
        ?string $root,
        bool $rootEstablished,
        array $pending,
        bool $cleanupJunk = false,
    ): array {
        $fileIndex = TimingsMerge::indexByFile($tests);
        $mergedPending = [];

        foreach ($pending as $path) {
            // @-suppressed: a batch enumerated by paths() can vanish or become
            // unreadable before this read runs (finding 11) - a race the code
            // already anticipates via the is_file() check below. Suppression
            // only silences PHP's native diagnostic; the explicit false-handling
            // and the injected warning immediately below are unchanged.
            $contents = @file_get_contents($path);

            if ($contents === false) {
                // A read failure is never treated as junk and never resets the
                // accumulator: an existing-but-unreadable batch (e.g. EACCES) is
                // left on disk for the next merge, a vanished batch is simply
                // gone, and either way every batch already applied in this pass
                // is preserved. Both load() and mergeToDisk() only skip.
                ($this->warn)(
                    (is_file($path)
                        ? '[warp] skipped unreadable pending timings batch: '
                        : '[warp] skipped vanished pending timings batch: ').$path.PHP_EOL
                );

                continue;
            }

            $batch = json_decode((string) $contents, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                ($this->warn)('[warp] skipped undecodable pending timings batch: '.$path.PHP_EOL);

                if ($cleanupJunk) {
                    $mergedPending[] = $path;
                }

                continue;
            }

            if (! is_array($batch)) {
                ($this->warn)('[warp] skipped invalid pending timings batch: '.$path.PHP_EOL);

                if ($cleanupJunk) {
                    $mergedPending[] = $path;
                }

                continue;
            }

            $batchRoot = isset($batch['root']) && is_string($batch['root']) ? $batch['root'] : null;

            if ($batchRoot !== null) {
                if (! $rootEstablished) {
                    $root = $batchRoot;
                    $rootEstablished = true;
                } elseif ($batchRoot !== $root) {
                    // Foreign batch: recorded against a different config dir.
                    // Warn-and-delete under the merge lock (cleanupJunk);
                    // skip-and-warn, never delete, on the read-only load path
                    // so a stray batch cannot flip the domain or the root.
                    ($this->warn)("[warp] skipped pending timings batch recorded against a different root ('{$batchRoot}' != '{$root}'): ".$path.PHP_EOL);

                    if ($cleanupJunk) {
                        $mergedPending[] = $path;
                    }

                    continue;
                }
            }

            $merged = TimingsMerge::apply($tests, $fileIndex, $batch);
            $tests = $merged['tests'];
            $fileIndex = $merged['fileIndex'];
            $mergedPending[] = $path;
        }

        return [$tests, $mergedPending, $root];
    }
}
