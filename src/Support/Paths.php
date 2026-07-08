<?php

declare(strict_types=1);

namespace RawPHP\Warp\Support;

final class Paths
{
    public static function canonical(string $path, string $root): ?string
    {
        $realRoot = realpath($root);
        $realPath = realpath($path);

        if ($realRoot === false || $realPath === false) {
            return null;
        }

        $root = self::normalize($realRoot);
        $path = self::normalize($realPath);

        if ($path === $root) {
            return '';
        }

        $prefix = rtrim($root, '/').'/';

        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        $relative = substr($path, strlen($prefix));

        while (str_starts_with($relative, './')) {
            $relative = substr($relative, 2);
        }

        return $relative;
    }

    private static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
