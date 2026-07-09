<?php

declare(strict_types=1);

namespace RawPHP\Warp\Cli;

use InvalidArgumentException;
use RawPHP\Warp\Timing\TimingStore;
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
        $store = TimingStore::fromEnv();

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--timings-dir=')) {
                $store = new TimingStore(substr($arg, strlen('--timings-dir=')));
            } elseif (str_starts_with($arg, '--')) {
                fwrite($stderr, "[warp] unknown option: {$arg}\n");

                return 2;
            } else {
                fwrite($stderr, "[warp] unknown argument: {$arg}\n");

                return 2;
            }
        }

        try {
            $merged = $store->mergeToDisk();
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
