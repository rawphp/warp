<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

use RawPHP\Warp\Support\Paths;

final class TestFileResolver
{
    /**
     * Pest evaluates its test classes, so PHPUnit reports "...eval()'d code" as
     * the file; the generated class carries the real path in a static instead.
     *
     * @param  class-string  $className
     */
    public static function resolve(string $className, string $reportedFile, string $root): ?string
    {
        $file = property_exists($className, '__filename')
            ? (string) $className::$__filename
            : $reportedFile;

        if (str_contains($file, "eval()'d code")) {
            return null;
        }

        return Paths::canonical($file, $root);
    }
}
