<?php

declare(strict_types=1);

namespace RawPHP\Warp\Support;

/**
 * Cheap PID liveness / signal helpers for local Unix processes.
 *
 * Used by snapshot-DB worker reaping; not a general process manager.
 *
 * @internal Package process plumbing; not host-facing.
 */
final class ProcessProbe
{
    public static function alive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        exec(sprintf('kill -0 %d 2>/dev/null', $pid), $output, $exit);

        return $exit === 0;
    }

    /** Best-effort signal; silent when the pid is gone or not owned. */
    public static function signal(int $pid, int $signal = 15): void
    {
        if ($pid <= 0) {
            return;
        }

        exec(sprintf('kill -%d %d 2>/dev/null', $signal, $pid));
    }
}
