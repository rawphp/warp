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

            // The scoped error handler above captured the @fopen warning; it is the
            // only diagnostic source. The old last-PHP-error fallback that followed
            // was unreachable - the handler returns true, so no PHP error was kept.
            if (is_string($warning)) {
                $message .= ': '.$warning;
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
