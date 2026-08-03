<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

/**
 * Pure merge math for the timings document.
 *
 * No filesystem, locks, warnings, or by-ref mutation — every method returns
 * new values. Callers in {@see TimingStore} own I/O and decide whether invalid
 * batches are deleted (merge) or left alone (load).
 */
final class TimingsMerge
{
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
     * Per-file supersede: a batch replaces file F's prior entries only when it
     * flags F complete (every enumerated test of F terminated in that process);
     * otherwise it upserts observed test ids. A worker that saw only a slice of a
     * file never flags it complete, so partial batches never delete siblings.
     *
     * @param  array<string, array{file: string, ms: float}>  $tests
     * @param  array<string, array<string, true>>  $fileIndex
     * @param  array<mixed>  $batch
     * @return array{tests: array<string, array{file: string, ms: float}>, fileIndex: array<string, array<string, true>>}
     */
    public static function apply(array $tests, array $fileIndex, array $batch): array
    {
        $clean = self::sanitizeTests(is_array($batch['tests'] ?? null) ? $batch['tests'] : []);
        $completeFiles = self::completeFilesOf($batch);

        if ($clean === [] && $completeFiles === []) {
            return ['tests' => $tests, 'fileIndex' => $fileIndex];
        }

        foreach ($completeFiles as $file) {
            foreach (array_keys($fileIndex[$file] ?? []) as $id) {
                unset($tests[$id]);
            }

            unset($fileIndex[$file]);
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

        return ['tests' => $tests, 'fileIndex' => $fileIndex];
    }

    /**
     * The files a batch flags complete. Tolerant of legacy/foreign payloads: a
     * non-map `complete` (e.g. an old boolean flag) yields no complete files, so
     * such a batch degrades to upsert-only rather than wrongly superseding.
     *
     * @param  array<mixed>  $batch
     * @return list<string>
     */
    public static function completeFilesOf(array $batch): array
    {
        $complete = $batch['complete'] ?? null;

        if (! is_array($complete)) {
            return [];
        }

        $files = [];

        foreach ($complete as $file => $isComplete) {
            if (is_string($file) && $isComplete === true) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @param  array<string, array{file: string, ms: float}>  $tests
     * @return array<string, array<string, true>>
     */
    public static function indexByFile(array $tests): array
    {
        $index = [];

        foreach ($tests as $id => $entry) {
            $index[$entry['file']][$id] = true;
        }

        return $index;
    }

    /**
     * Drop non-string ids and entries missing a finite numeric duration.
     *
     * @param  array<mixed>  $tests
     * @return array<string, array{file: string, ms: float}>
     */
    public static function sanitizeTests(array $tests): array
    {
        $clean = [];

        foreach ($tests as $id => $entry) {
            if (is_string($id) && is_array($entry)
                && is_string($entry['file'] ?? null) && is_numeric($entry['ms'] ?? null)
                && is_finite((float) $entry['ms'])) {
                $clean[$id] = ['file' => $entry['file'], 'ms' => (float) $entry['ms']];
            }
        }

        return $clean;
    }
}
