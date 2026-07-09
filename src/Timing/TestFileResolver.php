<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

use RawPHP\Warp\Support\Paths;
use ReflectionClass;
use Stringable;
use Throwable;

final class TestFileResolver
{
    /** @var array<string, string|null> */
    private static array $resolvedByClass = [];

    /** @var array<class-string, string|null> */
    private static array $filenameByClass = [];

    /** @var array<class-string, bool> */
    private static array $cacheableByClass = [];

    /**
     * Pest evaluates its test classes, so PHPUnit reports "...eval()'d code" as
     * the file; the generated class carries the real path in a static instead.
     *
     * @param  class-string  $className
     */
    public static function resolve(string $className, string $reportedFile, string $root): ?string
    {
        $cacheable = self::cacheableClass($className);
        $cacheKey = $root."\0".$className;

        if ($cacheable && array_key_exists($cacheKey, self::$resolvedByClass)) {
            return self::$resolvedByClass[$cacheKey];
        }

        try {
            $file = self::filenameForClass($className) ?? $reportedFile;

            $resolved = str_contains($file, "eval()'d code")
                ? null
                : self::canonical($file, $root);
        } catch (Throwable) {
            $resolved = str_contains($reportedFile, "eval()'d code")
                ? null
                : self::canonical($reportedFile, $root);
        }

        if ($cacheable) {
            self::$resolvedByClass[$cacheKey] = $resolved;
        }

        return $resolved;
    }

    /**
     * @param  class-string  $className
     */
    private static function filenameForClass(string $className): ?string
    {
        if (array_key_exists($className, self::$filenameByClass)) {
            return self::$filenameByClass[$className];
        }

        try {
            $class = new ReflectionClass($className);

            if (! $class->hasProperty('__filename')) {
                return self::$filenameByClass[$className] = null;
            }

            $property = $class->getProperty('__filename');

            if (! $property->isStatic() || ! $property->isPublic() || ! $property->isInitialized()) {
                return self::$filenameByClass[$className] = null;
            }

            $value = $property->getValue();

            if (! is_string($value) && ! $value instanceof Stringable) {
                return self::$filenameByClass[$className] = null;
            }

            return self::$filenameByClass[$className] = (string) $value;
        } catch (Throwable) {
            return self::$filenameByClass[$className] = null;
        }
    }

    /**
     * @param  class-string  $className
     */
    private static function cacheableClass(string $className): bool
    {
        if (array_key_exists($className, self::$cacheableByClass)) {
            return self::$cacheableByClass[$className];
        }

        try {
            return self::$cacheableByClass[$className] = (new ReflectionClass($className))->getFileName() !== false;
        } catch (Throwable) {
            return self::$cacheableByClass[$className] = false;
        }
    }

    private static function canonical(string $path, string $root): ?string
    {
        return Paths::canonical($path, $root);
    }
}
