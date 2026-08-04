<?php

declare(strict_types=1);

namespace RawPHP\Warp\Cli;

use InvalidArgumentException;
use RawPHP\Warp\Shard\DurationBalancedSharder;
use RawPHP\Warp\Shard\ShardDiscovery;
use RawPHP\Warp\Support\Paths;
use RawPHP\Warp\Timing\ShardTotals;
use RuntimeException;

final class ShardCommand
{
    /**
     * Reject shard totals large enough to make DurationBalancedSharder::plan's
     * array_fill either throw an uncatchable memory fatal or a ValueError before
     * any allocation happens. No real suite needs anywhere near this many shards.
     */
    private const MAX_SHARD_TOTAL = 10_000;

    /**
     * Single source of truth for the `warp shard` usage line, shared with
     * WarpCli's top-level usage block so the two can never drift (finding 21).
     */
    public const USAGE = 'warp shard <index>/<total> [paths...] [--timings-dir=DIR] [--suffix=Test.php] [--configuration=FILE]';

    /**
     * @param  list<string>  $args
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function run(array $args, $stdout, $stderr): int
    {
        $spec = null;
        $paths = [];
        $suffixOption = null;
        $configuration = null;

        $timings = TimingStoreArgumentParser::parse($args, function (string $arg) use (&$spec, &$paths, &$suffixOption, &$configuration): bool {
            if (str_starts_with($arg, '--suffix=')) {
                $suffixOption = substr($arg, strlen('--suffix='));

                if ($suffixOption === '') {
                    throw new InvalidArgumentException('[warp] --suffix must not be empty');
                }

                return true;
            }

            if (str_starts_with($arg, '--configuration=')) {
                $configuration = substr($arg, strlen('--configuration='));

                return true;
            }

            if ($spec === null && preg_match('#^(\d+)/(\d+)$#', $arg, $matches) === 1) {
                $spec = [(int) $matches[1], (int) $matches[2]];

                return true;
            }

            if (str_starts_with($arg, '--')) {
                return false;
            }

            $paths[] = $arg;

            return true;
        }, $stderr);

        if ($spec === null) {
            fwrite($stderr, '[warp] usage: '.self::USAGE."\n");

            return 2;
        }

        if ($spec[1] < 1 || $spec[1] > self::MAX_SHARD_TOTAL) {
            fwrite($stderr, "[warp] shard total out of range: {$spec[0]}/{$spec[1]} - total must be between 1 and ".self::MAX_SHARD_TOTAL."\n");

            return 2;
        }

        $root = getcwd() ?: '.';
        [$files, $canonicalRoot] = ShardDiscovery::resolve($root, $paths, $configuration, $suffixOption, $stderr);
        $files = self::canonicalFiles($files, $canonicalRoot);

        if ($files === []) {
            fwrite($stderr, "[warp] no test files discovered - nothing to shard\n");

            return 2;
        }

        // Both calls hit the same store instance, so TimingStore memoizes one
        // locked snapshot read across them (REQ-104, findings 2/17): pending/
        // is scanned once and storedRoot/totals can never observe two
        // different store states, even under a concurrent `warp merge`.
        $selection = ShardTotals::resolve(
            $timings->store->storedRoot(),
            $canonicalRoot,
            $timings->store->fileTotals(),
            $files,
            $timings->dirLabel,
            self::strictRootEnabled(),
        );

        if ($selection->message !== null) {
            fwrite($stderr, $selection->message."\n");
        }

        if ($selection->hardFailExit !== null) {
            return $selection->hardFailExit;
        }

        $shard = DurationBalancedSharder::assign($files, $selection->totals, $spec[0], $spec[1]);

        if ($shard === []) {
            fwrite($stderr, "[warp] shard {$spec[0]}/{$spec[1]} is empty - more shards than test files\n");

            return 3;
        }

        fwrite($stdout, implode("\n", $shard)."\n");

        return 0;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    public static function canonicalFiles(array $files, string $root): array
    {
        $canonical = [];

        foreach ($files as $file) {
            $path = Paths::canonical($file, $root);

            if ($path === null) {
                throw new RuntimeException('[warp] could not resolve real path for test file: '.$file);
            }

            $canonical[] = $path;
        }

        $canonical = array_values(array_unique($canonical));
        sort($canonical);

        return $canonical;
    }

    /**
     * Strict root mode makes a stored/canonical root mismatch a hard error (exit
     * 2) even when the recorded relative keys still match discovered files. Off by
     * default so the portable committed-baseline workflow works; opt in by setting
     * WARP_STRICT_ROOT to any non-empty value other than "0".
     */
    private static function strictRootEnabled(): bool
    {
        $value = getenv('WARP_STRICT_ROOT');

        return $value !== false && $value !== '' && $value !== '0';
    }
}
