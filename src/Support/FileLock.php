<?php

declare(strict_types=1);

namespace RawPHP\Warp\Support;

use Closure;
use RuntimeException;

final class FileLock
{
    /**
     * Run $callback under an exclusive flock. Throws if the lock file cannot
     * be opened or acquired.
     */
    public static function withLock(string $lockFile, Closure $callback): mixed
    {
        [$handle, $warning] = self::openExclusive($lockFile);

        if ($handle === false) {
            throw self::openFailure($lockFile, $warning);
        }

        return self::runLocked($handle, $callback, $lockFile);
    }

    /**
     * Run $whenLocked under an exclusive flock. When the lock file cannot be
     * opened (e.g. a read-only timings dir restored from CI cache), run
     * $whenUnopenable instead — never invent a lock elsewhere. Flock failure
     * after a successful open still throws.
     *
     * Callers that must degrade on unwritable dirs (e.g. TimingStore snapshot
     * reads against a CI-restored read-only artifact) pass the lockless path
     * as $whenUnopenable.
     */
    public static function withLockOr(string $lockFile, Closure $whenLocked, Closure $whenUnopenable): mixed
    {
        [$handle] = self::openExclusive($lockFile);

        if ($handle === false) {
            return $whenUnopenable();
        }

        return self::runLocked($handle, $whenLocked, $lockFile);
    }

    /**
     * @return array{0: resource|false, 1: string|null}
     */
    private static function openExclusive(string $lockFile): array
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

        return [$handle, is_string($warning) ? $warning : null];
    }

    private static function openFailure(string $lockFile, ?string $warning): RuntimeException
    {
        $message = '[warp] cannot open file lock at '.$lockFile;

        // The scoped error handler above captured the @fopen warning; it is the
        // only diagnostic source. The old last-PHP-error fallback that followed
        // was unreachable - the handler returns true, so no PHP error was kept.
        if (is_string($warning)) {
            $message .= ': '.$warning;
        }

        return new RuntimeException($message);
    }

    /**
     * @param  resource  $handle
     */
    private static function runLocked(mixed $handle, Closure $callback, string $lockFile): mixed
    {
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
