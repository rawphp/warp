<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use Closure;
use RawPHP\Warp\Support\FileLock;

final class SnapshotStore
{
    public function __construct(private readonly string $root) {}

    public function path(string $key): string
    {
        return $this->root.'/'.substr($key, 0, 16);
    }

    public function datadir(string $key): string
    {
        return $this->path($key).'/datadir';
    }

    public function exists(string $key): bool
    {
        return is_dir($this->datadir($key));
    }

    /** Serialize golden builds for one key across concurrent workers. */
    public function withLock(string $key, Closure $callback): mixed
    {
        Dirs::ensure($this->root);

        return FileLock::withLock($this->path($key).'.lock', $callback);
    }

    public function stagingPath(string $key): string
    {
        return $this->path($key).'.staging-'.getmypid();
    }

    /** Atomically publish a fully-built staging dir as the golden snapshot. */
    public function promote(string $staging, string $key): void
    {
        if (! rename($staging, $this->path($key))) {
            throw new RuntimeException('[warp] failed to promote snapshot '.$staging);
        }
    }

    /** Drop all but the $keep most recently used snapshots (mtime = LRU marker). */
    public function prune(int $keep = 3): void
    {
        $dirs = array_filter(
            glob($this->root.'/*', GLOB_ONLYDIR) ?: [],
            static fn (string $dir): bool => ! str_contains(basename($dir), '.staging-'),
        );

        usort($dirs, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        foreach (array_slice($dirs, $keep) as $dir) {
            Dirs::delete($dir);
            @unlink($dir.'.lock');
        }
    }
}
