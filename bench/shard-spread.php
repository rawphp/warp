#!/usr/bin/env php
<?php

declare(strict_types=1);

// S3 gate report: count-based vs duration-balanced shard spread from recorded timings.
// Usage: php bench/shard-spread.php <timings-dir> <shards> [paths...]

require __DIR__.'/../vendor/autoload.php';

use RawPHP\Warp\Shard\DurationBalancedSharder;
use RawPHP\Warp\Shard\TestFileFinder;
use RawPHP\Warp\Timing\TimingStore;

$dir = $argv[1] ?? null;
$shards = (int) ($argv[2] ?? 0);
$paths = array_slice($argv, 3) ?: ['tests'];

if ($dir === null || $shards < 2) {
    fwrite(STDERR, "usage: php bench/shard-spread.php <timings-dir> <shards> [paths...]\n");
    exit(2);
}

$totals = (new TimingStore($dir))->fileTotals();

if ($totals === []) {
    fwrite(STDERR, "[warp] no timings under {$dir} - run the suite with WARP_TIMINGS=1 first\n");
    exit(2);
}

$files = TestFileFinder::find($paths);
$known = array_intersect_key($totals, array_flip($files));
$fallback = $known === [] ? 1.0 : array_sum($known) / count($known);
$weight = static fn (string $file): float => $totals[$file] ?? $fallback;

// Baseline: what count-based CI matrices do today - equal-count alphabetical chunks.
$chunks = array_chunk($files, (int) ceil(count($files) / $shards));
$baseline = array_pad(
    array_map(static fn (array $chunk): float => array_sum(array_map($weight, $chunk)), $chunks),
    $shards,
    0.0,
);

$balanced = [];

for ($i = 1; $i <= $shards; $i++) {
    $balanced[] = array_sum(array_map($weight, DurationBalancedSharder::assign($files, $totals, $i, $shards)));
}

printf("%d files, %.1fms recorded, %d shards\n\n", count($files), array_sum(array_map($weight, $files)), $shards);
printf("%-6s  %18s  %18s\n", 'shard', 'count-based (ms)', 'warp LPT (ms)');

for ($i = 0; $i < $shards; $i++) {
    printf("%-6d  %18.1f  %18.1f\n", $i + 1, $baseline[$i], $balanced[$i]);
}

$stats = static function (array $bins): array {
    $mean = array_sum($bins) / count($bins);

    return [max($bins) - min($bins), $mean > 0 ? max($bins) / $mean : 0.0];
};

[$baseSpread, $baseRatio] = $stats($baseline);
[$warpSpread, $warpRatio] = $stats($balanced);

printf("\nspread (max-min): count-based %.1fms vs warp %.1fms\n", $baseSpread, $warpSpread);
printf("max/mean:         count-based %.3f vs warp %.3f\n", $baseRatio, $warpRatio);
echo "wall-clock is set by the slowest shard - max/mean ~1.000 means spread collapsed to the mean.\n";
