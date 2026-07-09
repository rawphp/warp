<?php

declare(strict_types=1);

namespace RawPHP\Warp\Shard;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class TestFileFinder
{
    /**
     * Paths come back in the form they were given. Consumers that compare
     * paths to stored timing keys canonicalize them at that boundary.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function find(array $paths, string $suffix = 'Test.php'): array
    {
        if ($suffix === '') {
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

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($clean, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }
}
