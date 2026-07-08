<?php

declare(strict_types=1);

namespace RawPHP\Warp\Cli;

use InvalidArgumentException;
use RawPHP\Warp\Shard\DurationBalancedSharder;
use RawPHP\Warp\Shard\TestFileFinder;
use RawPHP\Warp\Timing\TimingStore;
use RuntimeException;

final class ShardCommand
{
    /**
     * @param  list<string>  $args
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function run(array $args, $stdout, $stderr): int
    {
        $spec = null;
        $paths = [];
        $dir = '.warp/timings';
        $suffix = 'Test.php';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--timings-dir=')) {
                $dir = substr($arg, strlen('--timings-dir='));
            } elseif (str_starts_with($arg, '--suffix=')) {
                $suffix = substr($arg, strlen('--suffix='));
            } elseif ($spec === null && preg_match('#^(\d+)/(\d+)$#', $arg, $matches) === 1) {
                $spec = [(int) $matches[1], (int) $matches[2]];
            } else {
                $paths[] = $arg;
            }
        }

        if ($spec === null) {
            fwrite($stderr, "[warp] usage: warp shard <index>/<total> [paths...] [--timings-dir=DIR] [--suffix=Test.php]\n");

            return 2;
        }

        try {
            $files = TestFileFinder::find($paths === [] ? ['tests'] : $paths, $suffix);
            $totals = (new TimingStore($dir))->fileTotals();

            if ($totals === []) {
                fwrite($stderr, "[warp] no recorded timings under {$dir} - sharding count-balanced\n");
            }

            $shard = DurationBalancedSharder::assign($files, $totals, $spec[0], $spec[1]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            fwrite($stderr, $exception->getMessage()."\n");

            return 2;
        }

        if ($shard === []) {
            fwrite($stderr, "[warp] shard {$spec[0]}/{$spec[1]} is empty - more shards than test files\n");

            return 3;
        }

        fwrite($stdout, implode("\n", $shard)."\n");

        return 0;
    }
}
