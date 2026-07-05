<?php

declare(strict_types=1);

namespace RawPHP\Warp;

final class WarpMode
{
    public static function enabled(): bool
    {
        return in_array(getenv('WARP_MODE'), ['1', 'on', 'true'], true);
    }
}
