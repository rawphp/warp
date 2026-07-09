<?php

declare(strict_types=1);

namespace RawPHP\Warp\Cli;

use InvalidArgumentException;
use RuntimeException;

final class MergeCommand
{
    /**
     * @param  list<string>  $args
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function run(array $args, $stdout, $stderr): int
    {
        try {
            $timings = TimingStoreArgumentParser::parse($args, static fn (string $arg): bool => false);
            $merged = $timings->store->mergeToDisk();
        } catch (InvalidArgumentException|RuntimeException $exception) {
            fwrite($stderr, $exception->getMessage()."\n");

            return 2;
        }

        if ($merged === 0) {
            fwrite($stdout, "[warp] nothing to merge\n");

            return 0;
        }

        $label = $merged === 1 ? 'batch' : 'batches';
        fwrite($stdout, "[warp] merged {$merged} pending timing {$label}\n");

        return 0;
    }
}
