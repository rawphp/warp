<?php

declare(strict_types=1);

namespace RawPHP\Warp;

final class WarpMode
{
    public static function enabled(): bool
    {
        return self::flag('WARP_MODE');
    }

    public static function databaseEnabled(): bool
    {
        return self::flag('WARP_DB');
    }

    public static function timingsEnabled(): bool
    {
        return self::flag('WARP_TIMINGS');
    }

    private static function flag(string $var): bool
    {
        return in_array(getenv($var), ['1', 'on', 'true'], true);
    }
}
