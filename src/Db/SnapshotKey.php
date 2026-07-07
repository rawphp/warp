<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SnapshotKey
{
    /** Bump to invalidate every stored snapshot when the datadir layout or mysqld flag set changes. */
    public const FORMAT = 1;

    /**
     * @param  list<string>  $hashPaths
     * @param  list<string>  $buildCommand
     */
    public static function compute(array $hashPaths, string $mysqldVersion, string $database, array $buildCommand): string
    {
        $lines = [];

        foreach ($hashPaths as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $relative = basename($root).'/'.substr($file->getPathname(), strlen($root) + 1);
                $lines[] = $relative.':'.sha1_file($file->getPathname());
            }
        }

        sort($lines);

        $lines[] = 'format:'.self::FORMAT;
        $lines[] = 'mysqld:'.$mysqldVersion;
        $lines[] = 'database:'.$database;
        $lines[] = 'build:'.implode("\x1f", $buildCommand);

        return sha1(implode("\n", $lines));
    }
}
