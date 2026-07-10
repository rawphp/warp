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
     * @param  list<string>  $args
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function run(array $args, $stdout, $stderr): int
    {
        $spec = null;
        $paths = [];
        $suffix = 'Test.php';
        $suffixOption = null;
        $configuration = null;

        try {
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
            });
        } catch (InvalidArgumentException|RuntimeException $exception) {
            fwrite($stderr, $exception->getMessage()."\n");

            return 2;
        }

        if ($spec === null) {
            fwrite($stderr, "[warp] usage: warp shard <index>/<total> [paths...] [--timings-dir=DIR] [--suffix=Test.php]\n");

            return 2;
        }

        try {
            $root = getcwd() ?: '.';
            $canonicalRoot = $root;
            $allowOutsideRoot = false;

            if ($paths === []) {
                try {
                    $files = SuiteDiscovery::discover($root, $configuration);
                    if ($suffixOption !== null) {
                        fwrite($stderr, "[warp] --suffix={$suffixOption} ignored because phpunit.xml discovery controls test file suffixes\n");
                    }
                    $canonicalRoot = self::suiteRoot($root, $configuration);
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
                    fwrite($stderr, "[warp] --configuration={$configuration} ignored because explicit test paths bypass suite discovery\n");
                }
                $files = TestFileFinder::find($paths, $suffix);
            }

            $files = self::canonicalFiles($files, $canonicalRoot, $allowOutsideRoot);

            if ($files === []) {
                fwrite($stderr, "[warp] no test files discovered - nothing to shard\n");

                return 2;
            }

            $storedRoot = $timings->store->storedRoot();

            if ($storedRoot !== null && $storedRoot !== $canonicalRoot) {
                fwrite($stderr, "[warp] timings root mismatch: recorded against '{$storedRoot}' but this shard resolves keys against '{$canonicalRoot}' - re-record timings from the same config dir or pass the matching --configuration\n");

                return 2;
            }

            $totals = $timings->store->fileTotals();

            if ($totals === []) {
                fwrite($stderr, "[warp] no recorded timings under {$timings->dirLabel} - sharding count-balanced\n");
            } elseif (array_intersect_key($totals, array_flip($files)) === []) {
                fwrite($stderr, "[warp] recorded timings match no discovered file - likely path-form or stale-artifact mismatch; sharding count-balanced\n");
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

    private static function suiteRoot(string $root, ?string $configuration): string
    {
        if ($configuration === null) {
            return $root;
        }

        $path = self::absolutePath($root, $configuration);
        $realpath = realpath($path);

        return dirname($realpath === false ? $path : $realpath);
    }

    private static function absolutePath(string $root, string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }
}
