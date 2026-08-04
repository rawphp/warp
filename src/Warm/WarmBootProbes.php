<?php

declare(strict_types=1);

namespace RawPHP\Warp\Warm;

use Illuminate\Foundation\Application;
use RawPHP\Warp\Support\ObjectAccess;

/**
 * Opt-in diagnostic hooks for warm-base boot. Not part of the hermeticity
 * contract — only engaged when the matching env flag is set. Keeps
 * {@see \RawPHP\Warp\WarmApplicationFactory} boot focused on session assembly.
 *
 * @internal Package warm-engine plumbing; not host-facing.
 */
final class WarmBootProbes
{
    /**
     * When WARP_TRACE_BASE_RESOLVE is set, log every resolution into the base
     * container (class + stack) to /tmp/warp-base-resolve.log. Debugging aid
     * for "who is still resolving through the base mid-suite?".
     */
    public static function installTrace(Application $base): void
    {
        if (getenv('WARP_TRACE_BASE_RESOLVE') === false) {
            return;
        }

        $base->resolving(function ($object, $container) use ($base): void {
            if ($container === $base) {
                file_put_contents(
                    '/tmp/warp-base-resolve.log',
                    get_class($object)."\n".(new \Exception)->getTraceAsString()."\n\n",
                    FILE_APPEND,
                );
            }
        });
    }

    /**
     * When WARP_SENTINEL_BASE_INSTANCES is set, return a probe that fingerprints
     * the base container's instance keys so mid-run accumulation is attributed
     * to the leaking test at teardown.
     *
     * Any service resolved INTO THE BASE mid-run (through a boot-captured
     * closure or stale reference) becomes shared state for every later
     * sandbox — e.g. a base-resolved 'url' registers a 'routes' rebinding
     * whose inherited callbacks send later sandboxes into infinite recursion.
     * With the probe on, the leaking TEST is named at its own teardown instead
     * of silently poisoning the worker. Opt-in because leaks cascade-fail
     * every later test in the worker (the first named test is the culprit).
     *
     * @return array<string, callable(): string>
     */
    public static function hermeticityProbes(Application $base): array
    {
        if (getenv('WARP_SENTINEL_BASE_INSTANCES') === false) {
            return [];
        }

        return [
            'base.instances' => function () use ($base): string {
                $ids = array_keys(ObjectAccess::read($base, fn (): array => $this->instances));
                sort($ids);

                return implode('|', $ids);
            },
        ];
    }
}
