<?php

declare(strict_types=1);

namespace RawPHP\Warp;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use RawPHP\Warp\Sentinel\HermeticitySentinel;
use RawPHP\Warp\Sentinel\LeakReport;
use RawPHP\Warp\Support\ObjectAccess;
use RawPHP\Warp\Warm\BootSnapshot;
use RawPHP\Warp\Warm\WarmSession;

/**
 * Process-global warm base: boot Laravel once, hand each test a sandbox clone.
 *
 * Boot-time process state (dispatcher listeners, Eloquent boot memos, Artisan
 * bootstrappers, base instance ids) lives in {@see BootSnapshot}, held with the
 * base and hermeticity sentinel in a single {@see WarmSession}.
 */
final class WarmApplicationFactory
{
    private static ?WarmSession $session = null;

    private static int $bootCount = 0;

    /**
     * Return a per-test sandbox cloned from the once-booted base application.
     *
     * @param  Closure(): Application  $createClassicApplication
     */
    public static function sandbox(Closure $createClassicApplication, ResetManifest $manifest): Application
    {
        // ??= keeps a typed WarmSession after first boot — no re-read branch
        // that would leave a ?WarmSession hole for static analysis / runtime.
        $session = self::$session ??= self::bootBase($createClassicApplication);
        $session->snapshot->restoreOnto($session->base);

        $sandbox = clone $session->base;

        // The clone's instances array still anchors 'app'/Container at the base;
        // mirror Application::registerBaseBindings() for the sandbox and give it
        // its own config repository (array items copy by value on clone).
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        $sandbox->instance('config', clone $sandbox->make('config'));

        Container::setInstance($sandbox);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($sandbox);

        $manifest->apply($sandbox, $session->base);

        return $sandbox;
    }

    /**
     * Build the warm session; caller publishes it atomically via ??=.
     *
     * Factory statics only: locals until every piece exists, then a single
     * assign of a complete {@see WarmSession}. A mid-boot throw leaves
     * {@see $session} null — never a live base without snapshot/sentinel.
     * That does not clean half-booted Laravel process state after
     * {@see $createClassicApplication} has already run; process may still
     * be dirty. Documented trade-off — no cleanup layer here.
     *
     * @param  Closure(): Application  $createClassicApplication
     */
    private static function bootBase(Closure $createClassicApplication): WarmSession
    {
        $base = $createClassicApplication();

        // Resolve the DB manager into the base so every sandbox shares the
        // same manager (and therefore the same PDO connections): this keeps
        // RefreshDatabase's once-per-process migrate + per-test transaction
        // model working unchanged in warm mode.
        $base->make('db');

        // Resolve the queue manager into the base too. Otherwise the
        // FIRST-ever job dispatch in the process builds it through the Bus
        // dispatcher's closure (which captured the base app at deferred
        // provider load), leaving the manager and its first connection
        // bound to the BASE container — the dispatched job then resolves
        // its dependencies from the base, ignoring the running test's
        // container bindings/mocks. Resolved up front, the manifest's
        // per-sandbox repoint governs it from the very first test.
        if ($base->bound('queue')) {
            $base->make('queue');
        }

        if (getenv('WARP_TRACE_BASE_RESOLVE') !== false) {
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

        // Diagnostic probe (WARP_SENTINEL_BASE_INSTANCES=1): any service
        // resolved INTO THE BASE mid-run (through a boot-captured closure
        // or stale reference) becomes shared state for every later
        // sandbox — e.g. a base-resolved 'url' registers a 'routes'
        // rebinding whose inherited callbacks send later sandboxes into
        // infinite recursion. With the probe on, the leaking TEST is
        // named at its own teardown instead of silently poisoning the
        // worker. Opt-in because leaks cascade-fail every later test in
        // the worker (the first named test is the culprit).
        $probes = [];

        if (getenv('WARP_SENTINEL_BASE_INSTANCES') !== false) {
            $probes['base.instances'] = function () use ($base): string {
                $ids = array_keys(ObjectAccess::read($base, fn (): array => $this->instances));
                sort($ids);

                return implode('|', $ids);
            };
        }

        $sentinel = HermeticitySentinel::capture($base, $probes);
        $snapshot = BootSnapshot::capture($base);

        // Complete session only; ??= on the caller is the sole factory publish.
        $session = new WarmSession($base, $snapshot, $sentinel);
        self::$bootCount++;

        return $session;
    }

    public static function base(): ?Application
    {
        return self::$session?->base;
    }

    public static function bootCount(): int
    {
        return self::$bootCount;
    }

    /** Diff current global state against the pristine fingerprint; scrap a corrupted base. */
    public static function checkHermeticity(): LeakReport
    {
        if (! self::$session instanceof WarmSession) {
            return new LeakReport([], false);
        }

        $report = self::$session->sentinel->check(self::$session->base);

        if ($report->baseCorrupted) {
            self::scrap();
        }

        return $report;
    }

    /** Drop the warm base; the next sandbox request boots a pristine one. */
    public static function scrap(): void
    {
        self::$session = null;
    }
}
