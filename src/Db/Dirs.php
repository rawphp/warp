<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use Closure;
use RuntimeException;

final class Dirs
{
    /**
     * Max attempts when rmdir fails with "Directory not empty" under concurrent
     * FS churn (e.g. InnoDB #innodb_redo/*_tmp appearing mid-teardown).
     * Each attempt re-clears children then retries rmdir.
     */
    private const NOT_EMPTY_MAX_ATTEMPTS = 5;

    /**
     * Optional pre-flight hook invoked as fn(string $op, string $path): void
     * where $op is "unlink" or "rmdir". Used by unit tests to simulate concurrent
     * FS churn (mid-walk vanish / temporary not-empty). Leave null in production.
     *
     * @var null|Closure(string, string): void
     */
    public static ?Closure $beforeFsOp = null;

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
            self::unlinkPath($path);

            return;
        }

        self::deleteDirectory($path);
    }

    private static function deleteDirectory(string $path): void
    {
        for ($attempt = 1; $attempt <= self::NOT_EMPTY_MAX_ATTEMPTS; $attempt++) {
            self::clearChildren($path);

            if (self::tryRmdir($path)) {
                return;
            }

            // Path vanished entirely between clear and rmdir — success.
            if (! file_exists($path) && ! is_link($path)) {
                return;
            }

            // tryRmdir only returns false for retryable "directory not empty".
            // Fall through to the next attempt.
        }

        throw new RuntimeException(
            '[warp] cannot delete directory after '.self::NOT_EMPTY_MAX_ATTEMPTS
            .' attempts (still not empty): '.$path
        );
    }

    private static function clearChildren(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }

        $items = @scandir($path);
        if ($items === false) {
            // Directory vanished mid-walk — treat as cleared.
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$item;

            if (is_dir($child) && ! is_link($child)) {
                self::deleteDirectory($child);
            } else {
                self::unlinkPath($child);
            }
        }
    }

    private static function unlinkPath(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        self::invokeBeforeFsOp('unlink', $path);

        // Re-check after test hook / concurrent churn may have removed it.
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $ok = unlink($path);
        } finally {
            restore_error_handler();
        }

        if ($ok) {
            return;
        }

        // ENOENT / already gone = success (TOCTOU race).
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (is_string($warning) && self::isEnoentMessage($warning)) {
            return;
        }

        $detail = is_string($warning) ? ': '.$warning : '';

        throw new RuntimeException('[warp] cannot unlink '.$path.$detail);
    }

    /**
     * @return bool true when the path is gone (rmdir succeeded or ENOENT);
     *              false when the directory is temporarily non-empty (caller should retry)
     *
     * @throws RuntimeException on non-retryable rmdir failures
     */
    private static function tryRmdir(string $path): bool
    {
        if (! file_exists($path) && ! is_link($path)) {
            return true;
        }

        self::invokeBeforeFsOp('rmdir', $path);

        if (! file_exists($path) && ! is_link($path)) {
            return true;
        }

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $ok = rmdir($path);
        } finally {
            restore_error_handler();
        }

        if ($ok) {
            return true;
        }

        if (! file_exists($path) && ! is_link($path)) {
            return true;
        }

        if (is_string($warning) && self::isEnoentMessage($warning)) {
            return true;
        }

        if (is_string($warning) && self::isNotEmptyMessage($warning)) {
            return false;
        }

        $detail = is_string($warning) ? ': '.$warning : '';

        throw new RuntimeException('[warp] cannot rmdir '.$path.$detail);
    }

    private static function invokeBeforeFsOp(string $op, string $path): void
    {
        if (self::$beforeFsOp !== null) {
            (self::$beforeFsOp)($op, $path);
        }
    }

    private static function isEnoentMessage(string $message): bool
    {
        return str_contains($message, 'No such file or directory')
            || str_contains($message, 'errno=2');
    }

    private static function isNotEmptyMessage(string $message): bool
    {
        return str_contains($message, 'Directory not empty')
            || str_contains($message, 'not empty');
    }
}
