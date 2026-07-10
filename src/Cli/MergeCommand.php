<?php

declare(strict_types=1);

namespace RawPHP\Warp\Cli;

final class MergeCommand
{
    /**
     * @param  list<string>  $args
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function run(array $args, $stdout, $stderr): int
    {
        $timings = TimingStoreArgumentParser::parse($args, static fn (string $arg): bool => false);
        $merged = $timings->store->mergeToDisk();

        if ($merged === 0) {
            fwrite($stdout, "[warp] nothing to merge\n");

            return 0;
        }

        $label = $merged === 1 ? 'batch' : 'batches';
        fwrite($stdout, "[warp] merged {$merged} pending timing {$label}\n");

        return 0;
    }
}
