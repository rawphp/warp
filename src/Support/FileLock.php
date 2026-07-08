<?php

declare(strict_types=1);

namespace RawPHP\Warp\Support;

use Closure;
use RuntimeException;

final class FileLock
{
    public static function withLock(string $lockFile, Closure $callback): mixed
    {
        $handle = @fopen($lockFile, 'c');

        if ($handle === false) {
            throw new RuntimeException('[warp] cannot open file lock at '.$lockFile);
        }

        flock($handle, LOCK_EX);

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
