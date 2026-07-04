<?php

declare(strict_types=1);

namespace Warp;

final class WarpMode
{
    public static function enabled(): bool
    {
        return getenv('WARP_WARM') === '1';
    }
}
