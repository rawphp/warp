<?php

declare(strict_types=1);

namespace RawPHP\Warp\Support;

final class Stderr
{
    public static function write(string $message): void
    {
        if (defined('STDERR')) {
            fwrite(STDERR, $message);

            return;
        }

        file_put_contents('php://stderr', $message);
    }
}
