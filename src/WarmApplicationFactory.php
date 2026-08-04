<?php

declare(strict_types=1);

namespace RawPHP\Warp;

use Closure;
use Illuminate\Foundation\Application;
use RawPHP\Warp\Sentinel\HermeticitySentinel;
use RawPHP\Warp\Sentinel\LeakReport;
use RawPHP\Warp\Warm\BootSnapshot;
use RawPHP\Warp\Warm\SandboxBuilder;
use RawPHP\Warp\Warm\WarmBootProbes;
use RawPHP\Warp\Warm\WarmSession;

/**
 * Process-global warm base: boot Laravel once, hand each test a sandbox clone.
 *
 * Boot-time process state (dispatcher listeners, Eloquent boot memos, Artisan
 * bootstrappers, base instance ids) lives in {@see BootSnapshot}, held with the
 * base and hermeticity sentinel in a single {@see WarmSession}. Per-test clone
 * assembly is {@see SandboxBuilder}.
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

        return SandboxBuilder::from($session->base, $manifest);
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

        WarmBootProbes::installTrace($base);

        $sentinel = HermeticitySentinel::capture($base, WarmBootProbes::hermeticityProbes($base));
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
