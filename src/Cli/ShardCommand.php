<?php

declare(strict_types=1);

namespace RawPHP\Warp\Cli;

use InvalidArgumentException;
use RawPHP\Warp\Shard\DurationBalancedSharder;
use RawPHP\Warp\Shard\MissingConfigurationException;
use RawPHP\Warp\Shard\SuiteDiscovery;
use RawPHP\Warp\Shard\TestFileFinder;
use RawPHP\Warp\Support\Paths;
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
        $canonicalRoot = $root;

        if ($paths === []) {
            try {
                $files = SuiteDiscovery::discover($root, $configuration);
                if ($suffixOption !== null) {
                    fwrite($stderr, "[warp] --suffix={$suffixOption} ignored because phpunit.xml discovery controls test file suffixes\n");
                }
                $canonicalRoot = Paths::configRoot(SuiteDiscovery::rootConfigurationPath($root, $configuration), $root);
            } catch (MissingConfigurationException $exception) {
                if ($configuration !== null) {
                    throw $exception;
                }

                fwrite($stderr, "[warp] no phpunit.xml found - falling back to tests/Test.php discovery\n");
                $files = TestFileFinder::find(['tests'], $suffixOption ?? TestFileFinder::DEFAULT_SUFFIXES);
            }
        } else {
            if ($configuration !== null) {
                fwrite($stderr, "[warp] --configuration={$configuration} ignored for suite discovery (explicit test paths bypass discovery); still used for the timing-key root\n");
            }

            // Explicit paths bypass discovery but the timing-key root is still the
            // config dir the extension recorded against: honour --configuration for
            // the root, or probe for an implicit phpunit.xml exactly as discovery
            // would (finding 9). Only cwd-rooted runs with no config stay at getcwd.
            $configPath = SuiteDiscovery::rootConfigurationPath($root, $configuration);

            if ($configPath !== null) {
                $canonicalRoot = Paths::configRoot($configPath, $root);
            }

            $files = TestFileFinder::find($paths, $suffixOption ?? TestFileFinder::DEFAULT_SUFFIXES);
        }

        $files = self::canonicalFiles($files, $canonicalRoot);

        if ($files === []) {
            fwrite($stderr, "[warp] no test files discovered - nothing to shard\n");

            return 2;
        }

        // Both calls hit the same store instance, so TimingStore memoizes one
        // locked snapshot read across them (REQ-104, findings 2/17): pending/
        // is scanned once and storedRoot/totals can never observe two
        // different store states, even under a concurrent `warp merge`.
        $storedRoot = $timings->store->storedRoot();
        $totals = $timings->store->fileTotals();

        if ($storedRoot !== null && $storedRoot !== $canonicalRoot) {
            // Root-mismatch policy (finding 7, revised UR-087/REQ-576): a differing
            // absolute root is metadata only — per-file timing keys are stored
            // relative, so when they still match discovered files the timings are
            // usable on this checkout path. That is the committed/shared-baseline
            // workflow: a portable timings.json run on a differently-rooted clone
            // or CI runner (e.g. recorded under /Users/... , sharded under
            // /home/runner/...). Use the timings, warning that the root differs,
            // instead of failing the shard. If no key matches, the artifact is
            // pure stale/foreign (e.g. a CI cache restored to a renamed workspace)
            // so degrade to count-balanced. WARP_STRICT_ROOT restores the old
            // fail-loudly for callers who want a differing root to be a hard error
            // even when keys still match.
            if (array_intersect_key($totals, array_flip($files)) !== []) {
                if (self::strictRootEnabled()) {
                    fwrite($stderr, "[warp] timings root mismatch: recorded against '{$storedRoot}' but this shard resolves keys against '{$canonicalRoot}' - WARP_STRICT_ROOT is set, so this is a hard error; re-record timings from the same config dir or pass the matching --configuration\n");

                    return 2;
                }

                fwrite($stderr, "[warp] timings root differs: recorded against '{$storedRoot}' but this shard resolves keys against '{$canonicalRoot}' - recorded keys still match discovered files, so the timings are portable; using them (set WARP_STRICT_ROOT=1 to treat this as an error)\n");
            } else {
                fwrite($stderr, "[warp] timings root mismatch: recorded against '{$storedRoot}' but this shard resolves keys against '{$canonicalRoot}' - no recorded key matches a discovered file (stale or foreign artifact); sharding count-balanced\n");
                $totals = [];
            }
        } elseif ($totals === []) {
            fwrite($stderr, "[warp] no recorded timings under {$timings->dirLabel} - sharding count-balanced\n");
        } elseif (array_intersect_key($totals, array_flip($files)) === []) {
            fwrite($stderr, "[warp] recorded timings match no discovered file - likely path-form or stale-artifact mismatch; sharding count-balanced\n");
        }

        $shard = DurationBalancedSharder::assign($files, $totals, $spec[0], $spec[1]);

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
