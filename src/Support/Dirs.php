<?php

declare(strict_types=1);

namespace RawPHP\Warp\Support;

use Closure;
use RuntimeException;

/**
 * @internal Package filesystem helpers (ensure/delete); not a host-facing API.
 *           Documented only as the location of the old removed Db\Dirs alias.
 */
final class Dirs
{
    /**
     * Max attempts when rmdir fails with "Directory not empty" under concurrent
     * FS churn (e.g. InnoDB #innodb_redo/*_tmp appearing mid-teardown).
     * Each attempt re-clears children then retries rmdir.
     */
    private const NOT_EMPTY_MAX_ATTEMPTS = 5;

    /**
     * Fixed short sleep between not-empty rmdir retry attempts (~10–20ms).
     * Keeps delete self-healing under residual churn without a multi-100ms settle.
     */
    private const NOT_EMPTY_BACKOFF_US = 15_000;

    /**
     * @internal Test-only pre-flight hook invoked as fn(string $op, string $path): void
     * where $op is "unlink" or "rmdir". Used by unit tests to simulate concurrent
     * FS churn (mid-walk vanish / temporary not-empty). Must remain null in production.
     *
     * @var null|Closure(string, string): void
     */
    private static ?Closure $testBeforeFsOp = null;

    /**
     * @internal Install a test-only FS pre-op hook. Not part of the public package API.
     *
     * @param  null|Closure(string, string): void  $hook
     */
    public static function installTestBeforeFsOp(?Closure $hook): void
    {
        self::$testBeforeFsOp = $hook;
    }

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
            self::removeNode('unlink', $path);

            return;
        }

        self::deleteDirectory($path);
    }

    private static function deleteDirectory(string $path): void
    {
        for ($attempt = 1; $attempt <= self::NOT_EMPTY_MAX_ATTEMPTS; $attempt++) {
            self::clearChildren($path);

            if (self::removeNode('rmdir', $path)) {
                return;
            }

            // Path vanished entirely between clear and rmdir — success.
            if (! file_exists($path) && ! is_link($path)) {
                return;
            }

            // removeNode only returns false for retryable "directory not empty".
            // Back off briefly before the next attempt (except after the last).
            if ($attempt < self::NOT_EMPTY_MAX_ATTEMPTS) {
                usleep(self::NOT_EMPTY_BACKOFF_US);
            }
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
                self::removeNode('unlink', $child);
            }
        }
    }

    /**
     * Single pipeline for unlink/rmdir: test hook, warning capture, ENOENT success,
     * and (for rmdir) not-empty retry signal.
     *
     * @param  'unlink'|'rmdir'  $op
     * @return bool true when the path is gone (op succeeded or ENOENT);
     *              false only when rmdir reports temporary "directory not empty"
     *
     * @throws RuntimeException on non-retryable failures
     */
    private static function removeNode(string $op, string $path): bool
    {
        if (! file_exists($path) && ! is_link($path)) {
            return true;
        }

        self::invokeTestBeforeFsOp($op, $path);

        // Re-check after test hook / concurrent churn may have removed it.
        if (! file_exists($path) && ! is_link($path)) {
            return true;
        }

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $ok = $op === 'unlink' ? unlink($path) : rmdir($path);
        } finally {
            restore_error_handler();
        }

        if ($ok) {
            return true;
        }

        // ENOENT / already gone = success (TOCTOU race).
        if (! file_exists($path) && ! is_link($path)) {
            return true;
        }

        if (is_string($warning) && self::isEnoentMessage($warning)) {
            return true;
        }

        if ($op === 'rmdir' && is_string($warning) && self::isNotEmptyMessage($warning)) {
            return false;
        }

        $detail = is_string($warning) ? ': '.$warning : '';

        throw new RuntimeException('[warp] cannot '.$op.' '.$path.$detail);
    }

    private static function invokeTestBeforeFsOp(string $op, string $path): void
    {
        if (self::$testBeforeFsOp !== null) {
            (self::$testBeforeFsOp)($op, $path);
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
