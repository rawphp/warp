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

    /**
     * Canonical timing-key for a path against a root. Inside-root files keep the
     * existing root-relative key (unchanged byte-for-byte). Outside-root files -
     * now allowed on every caller path, not gated by a per-caller flag - get a
     * root-relative key with leading `../` segments computed from the real
     * common ancestor of root and path, so the SAME key is produced regardless
     * of the machine's absolute prefix so long as the relative layout matches
     * (UR-017: one key domain, no absolute machine-specific keys). Returns null
     * only when root or path cannot be resolved via realpath (e.g. missing).
     */
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

        if (str_starts_with($path, $prefix)) {
            $relative = substr($path, strlen($prefix));

            while (str_starts_with($relative, './')) {
                $relative = substr($relative, 2);
            }

            return $relative;
        }

        return self::relativeAcrossRoots($path, $root);
    }

    /**
     * True when $path resolves (via realpath) to somewhere inside (or equal to)
     * $root. This is a separate concern from canonical()'s key form: callers
     * that need to know whether a candidate file genuinely lives under a root
     * (e.g. TestFileResolver disambiguating reflection-derived files from the
     * PHPUnit-reported file) use this instead, since canonical() now always
     * succeeds for any resolvable path, inside or outside root.
     */
    public static function isInside(string $path, string $root): bool
    {
        $realRoot = realpath($root);
        $realPath = realpath($path);

        if ($realRoot === false || $realPath === false) {
            return false;
        }

        $root = self::normalize($realRoot);
        $path = self::normalize($realPath);

        if ($path === $root) {
            return true;
        }

        return str_starts_with($path, rtrim($root, '/').'/');
    }

    /**
     * Root-relative key with leading `../` segments: walk both the (normalized,
     * realpath-resolved) root and path from their common ancestor, emitting one
     * `..` per remaining root segment, then the path's own remaining segments.
     * Depends only on the relative layout around the common ancestor, not its
     * absolute depth or names, so two machines with the same layout produce the
     * identical string even under completely different absolute prefixes.
     */
    private static function relativeAcrossRoots(string $path, string $root): string
    {
        $rootParts = self::segments($root);
        $pathParts = self::segments($path);

        $common = 0;
        $max = min(count($rootParts), count($pathParts));

        while ($common < $max && $rootParts[$common] === $pathParts[$common]) {
            $common++;
        }

        $upCount = count($rootParts) - $common;
        $downParts = array_slice($pathParts, $common);

        return implode('/', array_merge(array_fill(0, $upCount, '..'), $downParts));
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
    }

    private static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
