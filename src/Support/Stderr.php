<?php

declare(strict_types=1);

namespace RawPHP\Warp\Support;

/**
 * @internal Process STDERR writer for package diagnostics; not a host-facing API.
 */
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
