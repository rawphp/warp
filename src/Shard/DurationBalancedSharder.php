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
     * @return list<string> the files of shard $index (1-based), path-sorted
     */
    public static function assign(array $files, array $fileTotalsMs, int $index, int $total): array
    {
        if ($total < 1 || $index < 1 || $index > $total) {
            throw new InvalidArgumentException("[warp] shard index out of range: {$index}/{$total}");
        }

        $known = array_intersect_key($fileTotalsMs, array_flip($files));
        $fallback = $known === [] ? 1.0 : array_sum($known) / count($known);

        $weights = [];

        foreach ($files as $file) {
            $weights[$file] = (float) ($fileTotalsMs[$file] ?? $fallback);
        }

        $order = array_keys($weights);
        usort($order, static fn (string $a, string $b): int => ($weights[$b] <=> $weights[$a]) ?: strcmp($a, $b));

        $loads = array_fill(0, $total, 0.0);
        $bins = array_fill(0, $total, []);

        foreach ($order as $file) {
            $lightest = (int) array_search(min($loads), $loads, true);
            $loads[$lightest] += $weights[$file];
            $bins[$lightest][] = $file;
        }

        $shard = $bins[$index - 1];
        sort($shard);

        return $shard;
    }
}
