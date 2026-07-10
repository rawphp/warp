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

    /** @var array<class-string, string|null> */
    private static array $fileByClass = [];

    /**
     * Pest evaluates its test classes, so PHPUnit reports "...eval()'d code" as
     * the file; the generated class carries the real path in a static instead.
     *
     * @param  class-string  $className
     */
    public static function resolve(string $className, string $reportedFile, string $root): ?string
    {
        // A class is cacheable when it resolves to a real file on disk. fileForClass
        // caches its one ReflectionClass, reused by classFileInsideRoot below, so no
        // extra reflection happens per resolve. Internal classes with no file are
        // not memoized - the same semantics the old duplicate helper enforced.
        $cacheable = self::fileForClass($className) !== null;
        $cacheKey = $root."\0".$className;

        if ($cacheable && array_key_exists($cacheKey, self::$resolvedByClass)) {
            return self::$resolvedByClass[$cacheKey];
        }

        try {
            $file = self::filenameForClass($className)
                ?? self::classFileInsideRoot($className, $root)
                ?? $reportedFile;

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
    private static function classFileInsideRoot(string $className, string $root): ?string
    {
        $file = self::fileForClass($className);

        if ($file === null || Paths::canonical($file, $root) === null) {
            return null;
        }

        return $file;
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
    private static function fileForClass(string $className): ?string
    {
        if (array_key_exists($className, self::$fileByClass)) {
            return self::$fileByClass[$className];
        }

        try {
            $file = (new ReflectionClass($className))->getFileName();

            return self::$fileByClass[$className] = is_string($file) ? $file : null;
        } catch (Throwable) {
            return self::$fileByClass[$className] = null;
        }
    }

    private static function canonical(string $path, string $root): ?string
    {
        return Paths::canonical($path, $root, allowOutside: true);
    }
}
