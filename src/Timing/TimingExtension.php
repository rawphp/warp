<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Event;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use RawPHP\Warp\Support\Paths;
use RawPHP\Warp\Support\Stderr;
use RawPHP\Warp\WarpMode;
use Throwable;

/**
 * PHPUnit extension entry for WARP_TIMINGS. Opt-in gate + store wiring only;
 * event subscriber bodies live in {@see ExtensionRegistrar}.
 */
final class TimingExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (! WarpMode::timingsEnabled()) {
            return;
        }

        $collector = new TimingCollector;
        $root = self::canonicalRoot($configuration);
        $store = TimingStore::fromEnv()->withRoot($root);
        $flush = static function () use ($collector, $store): void {
            self::flush($collector, $store);
        };

        ExtensionRegistrar::register($facade, $collector, $root, $flush);

        // Backstop: paratest workers and interrupted runs may never see
        // ExecutionFinished. The flush writes whatever per-file accounting shows —
        // files with an in-flight test stay incomplete and only upsert.
        register_shutdown_function($flush);
    }

    /** Telemetry wall-clock as float seconds, monotonic within a run. */
    public static function seconds(Event $event): float
    {
        $time = $event->telemetryInfo()->time();

        return $time->seconds() + $time->nanoseconds() / 1_000_000_000;
    }

    /**
     * Resolve an event's test to the canonical, root-relative file key used for
     * timing entries. Test methods go through the Pest-aware resolver; other test
     * kinds (.phpt) canonicalize their reported file directly.
     */
    public static function fileFor(Test $test, string $root): ?string
    {
        if ($test->isTestMethod()) {
            /** @var TestMethod $test */
            return TestFileResolver::resolve($test->className(), $test->file(), $root);
        }

        return Paths::canonical($test->file(), $root);
    }

    private static function flush(TimingCollector $collector, TimingStore $store): void
    {
        if ($collector->hasFlushed()) {
            return;
        }

        try {
            $collector->flush($store);
        } catch (Throwable $exception) {
            Stderr::write('[warp] timing flush failed: '.$exception->getMessage().PHP_EOL);

            return;
        }

        $unattributed = $collector->unattributedCount();

        if ($unattributed > 0) {
            Stderr::write("[warp] {$unattributed} test(s) could not be attributed to a file; their timings were not recorded".PHP_EOL);
        }
    }

    /**
     * Canonical timing-key root: the directory of the phpunit.xml actually used,
     * so keys line up with `warp shard` (which resolves the same config through
     * the same shared resolver). Falls back to the cwd only for pure CLI-path runs
     * with no XML configuration.
     */
    private static function canonicalRoot(Configuration $configuration): string
    {
        return Paths::configRoot(
            $configuration->hasConfigurationFile() ? $configuration->configurationFile() : null,
            (string) getcwd(),
        );
    }
}
