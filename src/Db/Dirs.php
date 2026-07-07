<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class Dirs
{
    public static function ensure(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException('[warp] cannot create directory '.$path);
        }
    }

    public static function delete(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (! is_dir($path) || is_link($path)) {
            unlink($path);

            return;
        }

        $children = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($children as $child) {
            $child->isDir() && ! $child->isLink()
                ? rmdir($child->getPathname())
                : unlink($child->getPathname());
        }

        rmdir($path);
    }
}
