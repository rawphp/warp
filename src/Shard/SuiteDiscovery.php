<?php

declare(strict_types=1);

namespace RawPHP\Warp\Shard;

use const PHP_VERSION;

use PHPUnit\TextUI\XmlConfiguration\Loader;
use RawPHP\Warp\Support\Paths;
use RuntimeException;
use SebastianBergmann\FileIterator\Facade as FileIterator;
use Throwable;

use function array_values;
use function is_dir;
use function is_file;
use function rtrim;
use function sort;
use function str_contains;
use function version_compare;

final class SuiteDiscovery
{
    /**
     * @return list<string>
     */
    public static function discover(string $root, ?string $configuration = null): array
    {
        $configurationPath = self::configurationPath($root, $configuration);

        if ($configurationPath === null) {
            throw new MissingConfigurationException('[warp] no phpunit.xml found at project root');
        }

        try {
            $loaded = (new Loader)->load($configurationPath);
        } catch (Throwable $exception) {
            throw new RuntimeException('[warp] cannot load phpunit configuration: '.$exception->getMessage(), previous: $exception);
        }

        $files = [];

        foreach ($loaded->testSuite() as $testSuite) {
            $exclude = [];

            foreach ($testSuite->exclude()->asArray() as $file) {
                $exclude[] = $file->path();
            }

            foreach ($testSuite->directories() as $directory) {
                if (! str_contains($directory->path(), '*') && ! is_dir($directory->path())) {
                    throw new RuntimeException('[warp] no such test suite directory: '.$directory->path());
                }

                if (! version_compare(PHP_VERSION, $directory->phpVersion(), $directory->phpVersionOperator()->asString())) {
                    continue;
                }

                foreach ((new FileIterator)->getFilesAsArray($directory->path(), $directory->suffix(), $directory->prefix(), $exclude) as $file) {
                    $files[$file] = true;
                }
            }

            foreach ($testSuite->files() as $file) {
                if (! is_file($file->path())) {
                    throw new RuntimeException('[warp] no such test suite file: '.$file->path());
                }

                if (! version_compare(PHP_VERSION, $file->phpVersion(), $file->phpVersionOperator()->asString())) {
                    continue;
                }

                $files[$file->path()] = true;
            }
        }

        $files = array_values(array_keys($files));
        sort($files);

        return $files;
    }

    public static function configurationPath(string $root, ?string $configuration = null): ?string
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        if ($configuration !== null) {
            $path = Paths::absolute($configuration, $root);

            if (! is_file($path)) {
                throw new RuntimeException('[warp] no such configuration file: '.$configuration);
            }

            return $path;
        }

        // Probe order and precedence mirror PHPUnit's own
        // vendor/phpunit/phpunit/src/TextUI/Configuration/Cli/XmlConfigurationFileFinder.php
        // exactly, so warp discovers the same configuration file phpunit would.
        foreach (['phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist'] as $filename) {
            $path = $root.DIRECTORY_SEPARATOR.$filename;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The configuration file that governs timing-key root computation, exposed so
     * ShardCommand feeds the shared resolver the same file the timing extension
     * saw — without loading the suite twice. An explicit --configuration is
     * resolved to an absolute path (its existence is not required here: root
     * computation tolerates a missing file, and explicit-path mode uses the flag
     * only for the root, never for discovery). Otherwise the implicit phpunit.xml
     * probe order is used; null when neither resolves.
     */
    public static function rootConfigurationPath(string $root, ?string $configuration = null): ?string
    {
        try {
            return self::configurationPath($root, $configuration);
        } catch (RuntimeException) {
            return Paths::absolute((string) $configuration, rtrim($root, DIRECTORY_SEPARATOR));
        }
    }
}
