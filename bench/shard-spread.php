#!/usr/bin/env php
<?php

declare(strict_types=1);

// S3 gate report: count-based vs duration-balanced shard spread from recorded timings.
// Usage: php bench/shard-spread.php <timings-dir> <shards> [paths...]

require __DIR__.'/../vendor/autoload.php';

use RawPHP\Warp\Cli\ShardCommand;
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

if ($files === []) {
    fwrite(STDERR, '[warp] no test files discovered under: '.implode(', ', $paths)."\n");
    fwrite(STDERR, "usage: php bench/shard-spread.php <timings-dir> <shards> [paths...]\n");
    exit(1);
}

// Reuse the CLI's own canonicalization so the bench exercises the exact same
// path-form contract (root-relative keys, outside-root ../ relativization,
// dedup + sort) that `warp shard` uses (finding 20) instead of forking it here.
$root = getcwd() ?: '.';
$files = ShardCommand::canonicalFiles($files, $root);

if (array_intersect_key($totals, array_flip($files)) === []) {
    fwrite(STDERR, "[warp] recorded timings match no discovered file - likely path-form or stale-artifact mismatch; sharding count-balanced\n");
}

$weights = DurationBalancedSharder::weights($files, $totals);
$plan = DurationBalancedSharder::plan($files, $totals, $shards);
$balanced = DurationBalancedSharder::loads($plan, $weights);

// Baseline: what count-based CI matrices do today - equal-count alphabetical chunks.
$chunks = array_chunk($files, (int) ceil(count($files) / $shards));
$baseline = array_pad(
    array_map(static fn (array $chunk): float => array_sum(array_map(
        static fn (string $file): float => $weights[$file],
        $chunk,
    )), $chunks),
    $shards,
    0.0,
);

printf("%d files, %.1fms recorded, %d shards\n\n", count($files), array_sum($weights), $shards);
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
