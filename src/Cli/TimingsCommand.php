<?php

declare(strict_types=1);

namespace RawPHP\Warp\Cli;

/**
 * @internal CLI command for `bin/warp timings`; not a host-facing API.
 */
final class TimingsCommand
{
    /**
     * @param  list<string>  $args
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function run(array $args, $stdout, $stderr): int
    {
        $timings = TimingStoreArgumentParser::parse($args, static fn (string $arg): bool => false, $stderr);
        $tests = $timings->store->load();

        if ($tests === []) {
            fwrite($stdout, "[warp] no timings recorded yet - run the suite with WARP_TIMINGS=1\n");

            return 0;
        }

        // arsort: fileTotals() is path-sorted (ksort); CLI lists slowest first.
        $totals = $timings->store->fileTotals();
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
