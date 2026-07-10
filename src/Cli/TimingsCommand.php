<?php

declare(strict_types=1);

namespace RawPHP\Warp\Cli;

use RawPHP\Warp\Timing\TimingStore;

final class TimingsCommand
{
    /**
     * @param  list<string>  $args
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function run(array $args, $stdout, $stderr): int
    {
        $timings = TimingStoreArgumentParser::parse($args, static fn (string $arg): bool => false);
        $tests = $timings->store->load();

        if ($tests === []) {
            fwrite($stdout, "[warp] no timings recorded yet - run the suite with WARP_TIMINGS=1\n");

            return 0;
        }

        $totals = TimingStore::aggregate($tests);
        arsort($totals);

        fwrite($stdout, sprintf(
            "[warp] %d tests across %d files - %.1fms recorded\n",
            count($tests),
            count($totals),
            array_sum($totals),
        ));
        fwrite($stdout, "slowest files:\n");

        foreach (array_slice($totals, 0, 10, true) as $file => $ms) {
            fwrite($stdout, sprintf("  %10.1fms  %s\n", $ms, $file));
        }

        return 0;
    }
}
