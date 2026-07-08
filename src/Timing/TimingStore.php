<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

use RawPHP\Warp\Db\Dirs;
use RuntimeException;

final class TimingStore
{
    /** Bump to discard every stored timing when the on-disk format changes. */
    private const VERSION = 1;

    public function __construct(private readonly string $dir) {}

    public static function fromEnv(): self
    {
        $dir = getenv('WARP_TIMINGS_DIR');

        return new self($dir !== false && $dir !== '' ? $dir : getcwd().'/.warp/timings');
    }

    /**
     * Lock-free per-process batch: unique filename, single atomic write.
     *
     * @param  array<string, array{file: string, ms: float}>  $tests
     */
    public function writePending(array $tests): void
    {
        if ($tests === []) {
            return;
        }

        Dirs::ensure($this->dir.'/pending');

        file_put_contents(
            $this->dir.'/pending/'.getmypid().'-'.bin2hex(random_bytes(4)).'.json',
            json_encode($tests, JSON_THROW_ON_ERROR),
        );
    }

    public function mergePending(): void
    {
        if (! is_dir($this->dir.'/pending')) {
            return;
        }

        $handle = fopen($this->dir.'/merge.lock', 'c');

        if ($handle === false) {
            throw new RuntimeException('[warp] cannot open timings lock in '.$this->dir);
        }

        flock($handle, LOCK_EX);

        try {
            $pending = glob($this->dir.'/pending/*.json') ?: [];

            if ($pending === []) {
                return;
            }

            sort($pending);

            $tests = $this->readMerged();

            foreach ($pending as $path) {
                $batch = json_decode((string) file_get_contents($path), true);

                if (is_array($batch)) {
                    $tests = self::apply($tests, $batch);
                }

                unlink($path);
            }

            $tmp = $this->dir.'/timings.json.tmp';
            file_put_contents($tmp, json_encode(['version' => self::VERSION, 'tests' => $tests], JSON_THROW_ON_ERROR));
            rename($tmp, $this->dir.'/timings.json');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string, array{file: string, ms: float}> */
    public function load(): array
    {
        $this->mergePending();

        return $this->readMerged();
    }

    /** @return array<string, float> file => total ms, path-sorted */
    public function fileTotals(): array
    {
        return self::aggregate($this->load());
    }

    /**
     * @param  array<string, array{file: string, ms: float}>  $tests
     * @return array<string, float>
     */
    public static function aggregate(array $tests): array
    {
        $totals = [];

        foreach ($tests as $entry) {
            $totals[$entry['file']] = ($totals[$entry['file']] ?? 0.0) + $entry['ms'];
        }

        ksort($totals);

        return $totals;
    }

    /**
     * A batch supersedes every previous entry for the files it covers, so
     * tests renamed or deleted within a re-run file can't linger.
     *
     * @param  array<string, array{file: string, ms: float}>  $tests
     * @param  array<mixed>  $batch
     * @return array<string, array{file: string, ms: float}>
     */
    private static function apply(array $tests, array $batch): array
    {
        $clean = [];

        foreach ($batch as $id => $entry) {
            if (is_string($id) && is_array($entry)
                && is_string($entry['file'] ?? null) && is_numeric($entry['ms'] ?? null)) {
                $clean[$id] = ['file' => $entry['file'], 'ms' => (float) $entry['ms']];
            }
        }

        if ($clean === []) {
            return $tests;
        }

        $covered = [];

        foreach ($clean as $entry) {
            $covered[$entry['file']] = true;
        }

        $tests = array_filter($tests, static fn (array $entry): bool => ! isset($covered[$entry['file']]));

        return [...$tests, ...$clean];
    }

    /** @return array<string, array{file: string, ms: float}> */
    private function readMerged(): array
    {
        if (! is_file($this->dir.'/timings.json')) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

        if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION || ! is_array($data['tests'] ?? null)) {
            return [];
        }

        return $data['tests'];
    }
}
