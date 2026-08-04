<?php

declare(strict_types=1);

namespace RawPHP\Warp\Support;

use Closure;

/**
 * Bind-to-$this helpers for poking framework objects that expose no public
 * setter for container/app/cache fields warm sandboxes must rewire per test.
 *
 * Prefer real public APIs when they exist; this is the escape hatch for
 * Illuminate internals that only Octane (and now Warp) need to touch.
 *
 * @internal Package support helper for warm reset/repoint; not host-facing.
 */
final class ObjectAccess
{
    public static function set(object $target, string $property, mixed $value): void
    {
        (function () use ($property, $value): void {
            $this->{$property} = $value;
        })->call($target);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $reader  Bound to $target as $this
     * @return T
     */
    public static function read(object $target, Closure $reader): mixed
    {
        return $reader->call($target);
    }

    /**
     * @param  Closure(): void  $writer  Bound to $target as $this
     */
    public static function write(object $target, Closure $writer): void
    {
        $writer->call($target);
    }
}
