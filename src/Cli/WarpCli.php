<?php

declare(strict_types=1);

namespace RawPHP\Warp\Cli;

final class WarpCli
{
    /**
     * @param  list<string>  $argv
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function run(array $argv, $stdout, $stderr): int
    {
        $rest = array_slice($argv, 2);

        return match ($argv[1] ?? null) {
            'shard' => ShardCommand::run($rest, $stdout, $stderr),
            'timings' => TimingsCommand::run($rest, $stdout, $stderr),
            default => self::usage($stderr),
        };
    }

    /** @param resource $stderr */
    private static function usage($stderr): int
    {
        fwrite($stderr, <<<'TXT'
warp - test engine CLI
usage:
  warp shard <index>/<total> [paths...] [--timings-dir=DIR] [--suffix=Test.php]
  warp timings [--timings-dir=DIR]

TXT);

        return 2;
    }
}
