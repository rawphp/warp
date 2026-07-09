<?php

declare(strict_types=1);

namespace RawPHP\Warp\Support;

use Closure;
use RuntimeException;

final class FileLock
{
    public static function withLock(string $lockFile, Closure $callback): mixed
    {
        $warning = null;

        error_clear_last();
        set_error_handler(function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $handle = @fopen($lockFile, 'c');
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            $message = '[warp] cannot open file lock at '.$lockFile;
            $error = error_get_last();
            $reason = $warning;

            if (! is_string($reason) && is_string($error['message'] ?? null)) {
                $reason = $error['message'];
            }

            if (is_string($reason)) {
                $message .= ': '.$reason;
            }

            throw new RuntimeException($message);
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new RuntimeException('[warp] cannot acquire file lock at '.$lockFile);
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
