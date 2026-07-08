<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

use RawPHP\Warp\Db\Dirs;
use RuntimeException;

final class TimingStore
{
    /** Bump to discard every stored timing when the on-disk format changes. */
    private const VERSION = 1;

    private static int $lastPendingTimestamp = 0;

    public function __construct(private readonly string $dir) {}

    public static function fromEnv(): self
    {
        $dir = getenv('WARP_TIMINGS_DIR');

        return new self($dir !== false && $dir !== '' ? $dir : getcwd().'/.warp/timings');
    }

    /**
     * Lock-free per-process batch: unique filename, atomic tmp+rename publish.
     *
     * @param  array<string, array{file: string, ms: float}>  $tests
     */
    public function writePending(array $tests): void
    {
        if ($tests === []) {
            return;
        }

        Dirs::ensure($this->dir.'/pending');

        $path = $this->dir.'/pending/'.self::nextPendingTimestamp().'-'.getmypid().'-'.bin2hex(random_bytes(4)).'.json';
        $tmp = $path.'.tmp';

        if (file_put_contents($tmp, json_encode($tests, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('[warp] cannot write pending timings batch to '.$tmp);
        }

        if (! rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('[warp] cannot publish pending timings batch to '.$path);
        }
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
            $pending = $this->pendingFiles();

            if ($pending === []) {
                return;
            }

            $tests = $this->readMerged();

            foreach ($pending as $path) {
                $batch = json_decode((string) file_get_contents($path), true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    self::warn('[warp] skipped undecodable pending timings batch: '.$path.PHP_EOL);

                    continue;
                }

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

    /** @return list<string> */
    private function pendingFiles(): array
    {
        $entries = scandir($this->dir.'/pending');

        if ($entries === false) {
            return [];
        }

        $files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ! str_ends_with($entry, '.json')) {
                continue;
            }

            $path = $this->dir.'/pending/'.$entry;

            if (! is_file($path)) {
                continue;
            }

            if (! preg_match('/^(\d+)-\d+-[a-f0-9]{8}\.json$/', $entry, $matches)) {
                self::warn('[warp] skipped old-format pending timings batch: '.$path.PHP_EOL);

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

    private static function nextPendingTimestamp(): int
    {
        $timestamp = (int) floor(microtime(true) * 1_000_000);

        if ($timestamp <= self::$lastPendingTimestamp) {
            $timestamp = self::$lastPendingTimestamp + 1;
        }

        self::$lastPendingTimestamp = $timestamp;

        return $timestamp;
    }

    private static function warn(string $message): void
    {
        if (defined('STDERR')) {
            fwrite(STDERR, $message);

            return;
        }

        file_put_contents('php://stderr', $message);
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
