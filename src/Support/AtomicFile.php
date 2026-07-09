<?php

declare(strict_types=1);

namespace RawPHP\Warp\Support;

use RuntimeException;

final class AtomicFile
{
    public static function write(
        string $path,
        string $contents,
        string $writeFailureMessage,
        string $publishFailureMessage,
    ): void {
        $tmp = $path.'.tmp';
        $bytes = file_put_contents($tmp, $contents);

        if ($bytes === false || $bytes < strlen($contents)) {
            @unlink($tmp);

            throw new RuntimeException($writeFailureMessage.' to '.$tmp);
        }

        if (! rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException($publishFailureMessage.' to '.$path);
        }
    }
}
