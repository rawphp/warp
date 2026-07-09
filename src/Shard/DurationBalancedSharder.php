<?php

declare(strict_types=1);

namespace RawPHP\Warp\Shard;

use InvalidArgumentException;

final class DurationBalancedSharder
{
    /**
     * Deterministic LPT bin-packing: every shard machine computes the full
     * assignment independently from the same timings and agrees without a
     * coordination service.
     *
     * @param  list<string>  $files
     * @param  array<string, float>  $fileTotalsMs
     * @return list<list<string>> path-sorted files per shard, in shard order
     */
    public static function plan(array $files, array $fileTotalsMs, int $shards): array
    {
        if ($shards < 1) {
            throw new InvalidArgumentException("[warp] shard index out of range: 1/{$shards}");
        }

        $weights = self::weights($files, $fileTotalsMs);
        $order = array_keys($weights);
        usort($order, static fn (string $a, string $b): int => ($weights[$b] <=> $weights[$a]) ?: strcmp($a, $b));

        $loads = array_fill(0, $shards, 0.0);
        $bins = array_fill(0, $shards, []);

        foreach ($order as $file) {
            $lightest = (int) array_search(min($loads), $loads, true);
            $loads[$lightest] += $weights[$file];
            $bins[$lightest][] = $file;
        }

        foreach ($bins as &$bin) {
            sort($bin);
        }

        return $bins;
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, float>  $fileTotalsMs
     * @return array<string, float>
     */
    public static function weights(array $files, array $fileTotalsMs): array
    {
        $known = array_intersect_key($fileTotalsMs, array_flip($files));
        $fallback = $known === [] ? 1.0 : array_sum($known) / count($known);

        $weights = [];

        foreach ($files as $file) {
            $weights[$file] = (float) ($fileTotalsMs[$file] ?? $fallback);
        }

        return $weights;
    }

    /**
     * @param  list<list<string>>  $plan
     * @param  array<string, float>  $weights
     * @return list<float>
     */
    public static function loads(array $plan, array $weights): array
    {
        $loads = [];

        foreach ($plan as $bin) {
            $load = 0.0;

            foreach ($bin as $file) {
                $load += $weights[$file] ?? 0.0;
            }

            $loads[] = $load;
        }

        return $loads;
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, float>  $fileTotalsMs
     * @return list<string> the files of shard $index (1-based), path-sorted
     */
    public static function assign(array $files, array $fileTotalsMs, int $index, int $total): array
    {
        if ($total < 1 || $index < 1 || $index > $total) {
            throw new InvalidArgumentException("[warp] shard index out of range: {$index}/{$total}");
        }

        return self::plan($files, $fileTotalsMs, $total)[$index - 1];
    }
}
