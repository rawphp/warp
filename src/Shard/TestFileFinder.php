<?php

declare(strict_types=1);

namespace RawPHP\Warp\Shard;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SebastianBergmann\FileIterator\ExcludeIterator;
use SebastianBergmann\FileIterator\Iterator as FileIteratorFilter;

final class TestFileFinder
{
    /**
     * PHPUnit's default suffixes for path arguments (vendor Merger.php:866).
     *
     * @var list<string>
     */
    public const DEFAULT_SUFFIXES = ['Test.php', '.phpt'];

    /**
     * Paths come back in the form they were given. Consumers that compare
     * paths to stored timing keys canonicalize them at that boundary.
     *
     * Directory walking is delegated to phpunit/php-file-iterator's own
     * Iterator/ExcludeIterator (the same classes PHPUnit's Facade composes),
     * so symlink-following, suffix matching, and hidden-directory exclusion
     * stay in parity with what phpunit itself discovers. The top-level path
     * is passed through as given rather than via the package's Factory (which
     * would realpath() it during wildcard resolution and break the
     * given-path-form contract above).
     *
     * @param  list<string>  $paths
     * @param  list<string>|string  $suffix
     * @return list<string>
     */
    public static function find(array $paths, array|string $suffix = self::DEFAULT_SUFFIXES): array
    {
        $suffixes = is_array($suffix) ? $suffix : [$suffix];

        if ($suffixes === [] || in_array('', $suffixes, true)) {
            throw new RuntimeException('[warp] test file suffix must not be empty');
        }

        $files = [];

        foreach ($paths as $path) {
            $clean = rtrim($path, DIRECTORY_SEPARATOR);

            if (is_file($clean)) {
                $files[] = $clean;

                continue;
            }

            if (! is_dir($clean)) {
                throw new RuntimeException('[warp] no such test path: '.$path);
            }

            $iterator = new FileIteratorFilter(
                $clean,
                new RecursiveIteratorIterator(
                    new ExcludeIterator(
                        new RecursiveDirectoryIterator(
                            $clean,
                            FilesystemIterator::FOLLOW_SYMLINKS | FilesystemIterator::SKIP_DOTS,
                        ),
                        [],
                    ),
                ),
                $suffixes,
            );

            foreach ($iterator as $file) {
                $files[] = $file->getPathname();
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }
}
