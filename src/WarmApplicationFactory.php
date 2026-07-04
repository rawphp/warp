<?php

declare(strict_types=1);

namespace Warp;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Warp\Sentinel\HermeticitySentinel;
use Warp\Sentinel\LeakReport;

final class WarmApplicationFactory
{
    private static ?Application $base = null;

    private static int $bootCount = 0;

    private static ?HermeticitySentinel $sentinel = null;

    /**
     * Return a per-test sandbox cloned from the once-booted base application.
     *
     * @param  Closure(): Application  $createClassicApplication
     */
    public static function sandbox(Closure $createClassicApplication, ResetManifest $manifest): Application
    {
        if (! self::$base instanceof Application) {
            self::$base = $createClassicApplication();
            self::$bootCount++;

            // Resolve the DB manager into the base so every sandbox shares the
            // same manager (and therefore the same PDO connections): this keeps
            // RefreshDatabase's once-per-process migrate + per-test transaction
            // model working unchanged in warm mode.
            self::$base->make('db');

            self::$sentinel = HermeticitySentinel::capture(self::$base);
        }

        $sandbox = clone self::$base;

        // The clone's instances array still anchors 'app'/Container at the base;
        // mirror Application::registerBaseBindings() for the sandbox and give it
        // its own config repository (array items copy by value on clone).
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        $sandbox->instance('config', clone $sandbox->make('config'));

        Container::setInstance($sandbox);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($sandbox);

        $manifest->apply($sandbox, self::$base);

        return $sandbox;
    }

    public static function base(): ?Application
    {
        return self::$base;
    }

    public static function bootCount(): int
    {
        return self::$bootCount;
    }

    /** Diff current global state against the pristine fingerprint; scrap a corrupted base. */
    public static function checkHermeticity(): LeakReport
    {
        if (! self::$base instanceof Application || self::$sentinel === null) {
            return new LeakReport([], false);
        }

        $report = self::$sentinel->check(self::$base);

        if ($report->baseCorrupted) {
            self::scrap();
        }

        return $report;
    }

    /** Drop the warm base; the next sandbox request boots a pristine one. */
    public static function scrap(): void
    {
        self::$base = null;
        self::$sentinel = null;
    }
}
