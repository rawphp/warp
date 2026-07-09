<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Support\AtomicFile;
use RawPHP\Warp\Support\FileLock;
use RawPHP\Warp\Support\Stderr;
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
    public function writePending(array $tests, bool $complete = true): void
    {
        if ($tests === []) {
            return;
        }

        Dirs::ensure($this->dir.'/pending');

        $path = $this->dir.'/pending/'.self::nextPendingTimestamp().'-'.getmypid().'-'.bin2hex(random_bytes(4)).'.json';

        $encoded = json_encode(['complete' => $complete, 'tests' => $tests], JSON_THROW_ON_ERROR);
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
            $pending = $this->pendingFiles();

            if ($pending === []) {
                return 0;
            }

            [$tests, $mergedPending] = $this->mergedWithPending($pending, true);

            AtomicFile::write(
                $this->dir.'/timings.json',
                json_encode(['version' => self::VERSION, 'tests' => $tests], JSON_THROW_ON_ERROR),
                '[warp] cannot write merged timings',
                '[warp] cannot publish merged timings',
            );

            foreach ($mergedPending as $path) {
                if (! @unlink($path)) {
                    Stderr::write('[warp] cannot delete merged pending timings batch at '.$path.PHP_EOL);
                }
            }

            return count($mergedPending);
        });
    }

    /** @return array<string, array{file: string, ms: float}> */
    public function load(): array
    {
        if (! is_dir($this->dir.'/pending')) {
            return $this->readMerged();
        }

        [$tests] = $this->mergedWithPending($this->pendingFiles());

        return $tests;
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
                Stderr::write('[warp] skipped old-format pending timings batch: '.$path.PHP_EOL);

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

    /**
     * @param  list<string>  $pending
     * @return array{0: array<string, array{file: string, ms: float}>, 1: list<string>}
     */
    private function mergedWithPending(array $pending, bool $cleanupJunk = false): array
    {
        $tests = $this->readMerged();
        $fileIndex = self::indexByFile($tests);
        $mergedPending = [];

        foreach ($pending as $path) {
            $contents = file_get_contents($path);

            if ($contents === false && (! $cleanupJunk || ! is_file($path))) {
                $tests = $this->readMerged();
                $fileIndex = self::indexByFile($tests);

                continue;
            }

            $batch = json_decode((string) $contents, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Stderr::write('[warp] skipped undecodable pending timings batch: '.$path.PHP_EOL);

                if ($cleanupJunk) {
                    $mergedPending[] = $path;
                }

                continue;
            }

            if (! is_array($batch)) {
                Stderr::write('[warp] skipped invalid pending timings batch: '.$path.PHP_EOL);

                if ($cleanupJunk) {
                    $mergedPending[] = $path;
                }

                continue;
            }

            $tests = self::apply($tests, $fileIndex, $batch);
            $mergedPending[] = $path;
        }

        return [$tests, $mergedPending];
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
     * Complete batches supersede whole files; incomplete crash batches only upsert observed test IDs.
     *
     * @param  array<string, array{file: string, ms: float}>  $tests
     * @param  array<string, array<string, true>>  $fileIndex
     * @param  array<mixed>  $batch
     * @return array<string, array{file: string, ms: float}>
     */
    private static function apply(array $tests, array &$fileIndex, array $batch): array
    {
        $clean = [];
        $batchTests = $batch['tests'] ?? null;

        if (! is_array($batchTests)) {
            return $tests;
        }

        foreach ($batchTests as $id => $entry) {
            if (is_string($id) && is_array($entry)
                && is_string($entry['file'] ?? null) && is_numeric($entry['ms'] ?? null)) {
                $clean[$id] = ['file' => $entry['file'], 'ms' => (float) $entry['ms']];
            }
        }

        if ($clean === []) {
            return $tests;
        }

        if (($batch['complete'] ?? false) === true) {
            $covered = [];

            foreach ($clean as $entry) {
                $covered[$entry['file']] = true;
            }

            foreach (array_keys($covered) as $file) {
                foreach (array_keys($fileIndex[$file] ?? []) as $id) {
                    unset($tests[$id]);
                }

                unset($fileIndex[$file]);
            }
        }

        foreach ($clean as $id => $entry) {
            if (isset($tests[$id])) {
                $oldFile = $tests[$id]['file'];
                unset($fileIndex[$oldFile][$id]);

                if (($fileIndex[$oldFile] ?? []) === []) {
                    unset($fileIndex[$oldFile]);
                }
            }

            $tests[$id] = $entry;
            $fileIndex[$entry['file']][$id] = true;
        }

        return $tests;
    }

    /**
     * @param  array<string, array{file: string, ms: float}>  $tests
     * @return array<string, array<string, true>>
     */
    private static function indexByFile(array $tests): array
    {
        $index = [];

        foreach ($tests as $id => $entry) {
            $index[$entry['file']][$id] = true;
        }

        return $index;
    }

    /** @return array<string, array{file: string, ms: float}> */
    private function readMerged(): array
    {
        if (! is_file($this->dir.'/timings.json')) {
            return [];
        }

        $contents = file_get_contents($this->dir.'/timings.json');

        if ($contents === false) {
            throw new RuntimeException('[warp] cannot read timings from '.$this->dir.'/timings.json');
        }

        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('[warp] cannot decode timings from '.$this->dir.'/timings.json: '.json_last_error_msg());
        }

        if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION || ! is_array($data['tests'] ?? null)) {
            return [];
        }

        $tests = [];

        foreach ($data['tests'] as $id => $entry) {
            if (is_string($id) && is_array($entry)
                && is_string($entry['file'] ?? null) && is_numeric($entry['ms'] ?? null)) {
                $tests[$id] = ['file' => $entry['file'], 'ms' => (float) $entry['ms']];
            }
        }

        return $tests;
    }
}
