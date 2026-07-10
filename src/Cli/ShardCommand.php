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
     * @param  list<string>  $args
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function run(array $args, $stdout, $stderr): int
    {
        $spec = null;
        $paths = [];
        $suffix = ['Test.php', '.phpt'];
        $suffixOption = null;
        $configuration = null;

        $timings = TimingStoreArgumentParser::parse($args, function (string $arg) use (&$spec, &$paths, &$suffix, &$suffixOption, &$configuration): bool {
            if (str_starts_with($arg, '--suffix=')) {
                $suffix = substr($arg, strlen('--suffix='));
                $suffixOption = $suffix;

                if ($suffix === '') {
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
            fwrite($stderr, "[warp] usage: warp shard <index>/<total> [paths...] [--timings-dir=DIR] [--suffix=Test.php]\n");

            return 2;
        }

        if ($spec[1] < 1 || $spec[1] > self::MAX_SHARD_TOTAL) {
            fwrite($stderr, "[warp] shard total out of range: {$spec[0]}/{$spec[1]} - total must be between 1 and ".self::MAX_SHARD_TOTAL."\n");

            return 2;
        }

        $root = getcwd() ?: '.';
        $canonicalRoot = $root;
        $allowOutsideRoot = false;

        if ($paths === []) {
            try {
                $files = SuiteDiscovery::discover($root, $configuration);
                if ($suffixOption !== null) {
                    fwrite($stderr, "[warp] --suffix={$suffixOption} ignored because phpunit.xml discovery controls test file suffixes\n");
                }
                $canonicalRoot = Paths::configRoot(SuiteDiscovery::rootConfigurationPath($root, $configuration), $root);
                $allowOutsideRoot = true;
            } catch (MissingConfigurationException $exception) {
                if ($configuration !== null) {
                    throw $exception;
                }

                fwrite($stderr, "[warp] no phpunit.xml found - falling back to tests/Test.php discovery\n");
                $files = TestFileFinder::find(['tests'], $suffix);
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
                $allowOutsideRoot = true;
            }

            $files = TestFileFinder::find($paths, $suffix);
        }

        $files = self::canonicalFiles($files, $canonicalRoot, $allowOutsideRoot);

        if ($files === []) {
            fwrite($stderr, "[warp] no test files discovered - nothing to shard\n");

            return 2;
        }

        $storedRoot = $timings->store->storedRoot();
        $totals = $timings->store->fileTotals();

        if ($storedRoot !== null && $storedRoot !== $canonicalRoot) {
            // Middle-path mismatch policy (finding 7, supersedes UR-016's
            // unconditional fail-loudly): if stored keys would still match
            // discovered files, this is a real misconfiguration worth stopping
            // for; if none match, the artifact is pure stale/foreign (e.g. a CI
            // cache restored to a renamed workspace) so degrade instead of
            // failing the whole shard matrix.
            if (array_intersect_key($totals, array_flip($files)) !== []) {
                fwrite($stderr, "[warp] timings root mismatch: recorded against '{$storedRoot}' but this shard resolves keys against '{$canonicalRoot}' - recorded keys still match discovered files, so this is a real misconfiguration; re-record timings from the same config dir or pass the matching --configuration\n");

                return 2;
            }

            fwrite($stderr, "[warp] timings root mismatch: recorded against '{$storedRoot}' but this shard resolves keys against '{$canonicalRoot}' - no recorded key matches a discovered file (stale or foreign artifact); sharding count-balanced\n");
            $totals = [];
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
    private static function canonicalFiles(array $files, string $root, bool $allowOutsideRoot = false): array
    {
        $canonical = [];

        foreach ($files as $file) {
            $path = Paths::canonical($file, $root, $allowOutsideRoot);

            if ($path === null) {
                throw new RuntimeException('[warp] test path is outside project root: '.$file);
            }

            $canonical[] = $path;
        }

        $canonical = array_values(array_unique($canonical));
        sort($canonical);

        return $canonical;
    }
}
