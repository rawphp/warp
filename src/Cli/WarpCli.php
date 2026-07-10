<?php

declare(strict_types=1);

namespace RawPHP\Warp\Cli;

use Throwable;

final class WarpCli
{
    /**
     * @param  list<string>  $argv
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function run(array $argv, $stdout, $stderr): int
    {
        try {
            $rest = array_slice($argv, 2);

            return match ($argv[1] ?? null) {
                'merge' => MergeCommand::run($rest, $stdout, $stderr),
                'shard' => ShardCommand::run($rest, $stdout, $stderr),
                'timings' => TimingsCommand::run($rest, $stdout, $stderr),
                default => self::usage($stderr),
            };
        } catch (Throwable $exception) {
            // Single error boundary: any Throwable from a command (including the
            // JsonException and ValueError the per-command catches used to miss)
            // becomes a diagnostic on the injected stderr and exit 2.
            $message = $exception->getMessage();

            if (! str_starts_with($message, '[warp]')) {
                $message = '[warp] '.$message;
            }

            fwrite($stderr, $message."\n");

            return 2;
        }
    }

    /** @param resource $stderr */
    private static function usage($stderr): int
    {
        // The shard line is sourced from ShardCommand::USAGE so the top-level and
        // per-command usage strings cannot drift (finding 21).
        $shard = ShardCommand::USAGE;

        fwrite($stderr, <<<TXT
warp - test engine CLI
usage:
  warp merge [--timings-dir=DIR]
  {$shard}
  warp timings [--timings-dir=DIR]

TXT);

        return 2;
    }
}
