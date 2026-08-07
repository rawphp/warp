<?php

declare(strict_types=1);

namespace RawPHP\Warp\Cli;

use Throwable;

/**
 * @internal bin/warp entry dispatcher; hosts invoke the binary, not this class.
 */
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
            $command = $argv[1] ?? null;
            $rest = array_slice($argv, 2);

            // Bare binary: usage is an error (missing command). Explicit help is success.
            if ($command === null) {
                return self::usage($stderr, 2);
            }

            if (self::isHelpToken($command)) {
                return self::usage($stderr, 0);
            }

            if (self::argsWantHelp($rest)) {
                return match ($command) {
                    'merge', 'shard', 'timings' => self::usage($stderr, 0),
                    default => self::usage($stderr, 2),
                };
            }

            return match ($command) {
                'merge' => MergeCommand::run($rest, $stdout, $stderr),
                'shard' => ShardCommand::run($rest, $stdout, $stderr),
                'timings' => TimingsCommand::run($rest, $stdout, $stderr),
                default => self::usage($stderr, 2),
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
    private static function usage($stderr, int $exitCode = 2): int
    {
        // The shard line is sourced from ShardCommand::USAGE so the top-level and
        // per-command usage strings cannot drift (finding 21).
        $shard = ShardCommand::USAGE;

        fwrite($stderr, <<<TXT
warp - timings and duration-balanced CI shards for Pest/PHPUnit

usage:
  warp merge [--timings-dir=DIR]
      Fold pending timing batches into timings.json
  {$shard}
      Print this shard's test files (duration-balanced when timings exist)
  warp timings [--timings-dir=DIR]
      Summarize recorded timings (slowest files first)

record timings:
  1. Register RawPHP\\Warp\\Timing\\TimingExtension in phpunit.xml
  2. WARP_TIMINGS=1 ./vendor/bin/pest
  3. ./vendor/bin/warp merge

TXT);

        return $exitCode;
    }

    private static function isHelpToken(?string $token): bool
    {
        return $token === '-h' || $token === '--help' || $token === 'help';
    }

    /** @param list<string> $args */
    private static function argsWantHelp(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg === '-h' || $arg === '--help') {
                return true;
            }
        }

        return false;
    }
}
