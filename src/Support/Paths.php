<?php

declare(strict_types=1);

namespace RawPHP\Warp\Support;

final class Paths
{
    /**
     * The canonical timing-key root shared by the write side (TimingExtension)
     * and the read side (ShardCommand): the phpunit config file's real directory
     * when a config governs the run, or the cwd when none does. Resolving through
     * realpath() means a symlinked config yields one root on both sides, so keys
     * recorded during a run always line up with the keys a later shard computes.
     */
    public static function configRoot(?string $configFile, string $cwd): string
    {
        if ($configFile === null) {
            return $cwd;
        }

        $realpath = realpath($configFile);

        return dirname($realpath === false ? $configFile : $realpath);
    }

    public static function canonical(string $path, string $root, bool $allowOutside = false): ?string
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
            return $allowOutside ? $path : null;
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
