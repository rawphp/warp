<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

use Closure;
use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Event;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Event\TestSuite\Loaded;
use PHPUnit\Event\TestSuite\LoadedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use RawPHP\Warp\Support\Paths;
use RawPHP\Warp\Support\Stderr;
use RawPHP\Warp\WarpMode;
use Throwable;

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

        // Enumerate every test of the full, pre-filter suite. Paratest injects a
        // per-method filter AFTER TestSuite\Loaded fires, so a --functional worker
        // still enumerates the whole file here and can never flag it complete when
        // it runs only a slice.
        $facade->registerSubscriber(new class($collector, $root) implements LoadedSubscriber
        {
            public function __construct(
                private readonly TimingCollector $collector,
                private readonly string $root,
            ) {}

            public function notify(Loaded $event): void
            {
                foreach ($event->testSuite()->tests()->asArray() as $test) {
                    $this->collector->enumerated(
                        $test->id(),
                        TimingExtension::fileFor($test, $this->root),
                    );
                }
            }
        });

        $facade->registerSubscriber(new class($collector, $root) implements PreparationStartedSubscriber
        {
            public function __construct(
                private readonly TimingCollector $collector,
                private readonly string $root,
            ) {}

            public function notify(PreparationStarted $event): void
            {
                $this->collector->started(
                    $event->test()->id(),
                    TimingExtension::seconds($event),
                    TimingExtension::fileFor($event->test(), $this->root),
                );
            }
        });

        $facade->registerSubscriber(new class($collector, $root) implements FinishedSubscriber
        {
            public function __construct(
                private readonly TimingCollector $collector,
                private readonly string $root,
            ) {}

            public function notify(Finished $event): void
            {
                $test = $event->test();
                $file = TimingExtension::fileFor($test, $this->root);

                if ($test->isTestMethod()) {
                    $this->collector->finished($test->id(), $file, TimingExtension::seconds($event));

                    return;
                }

                // Non-method tests (.phpt) emit Finished but carry no timing;
                // still close their accounting entry so the file can complete.
                $this->collector->terminated($test->id(), $file);
            }
        });

        // Every other terminal outcome closes an accounting entry without a
        // duration: setUp/requirement skips, errors, and incomplete markings all
        // reach one of these even when Test\Finished never fires (findings 5, 16).
        $facade->registerSubscriber(new class($collector, $root) implements SkippedSubscriber
        {
            public function __construct(
                private readonly TimingCollector $collector,
                private readonly string $root,
            ) {}

            public function notify(Skipped $event): void
            {
                $this->collector->terminated(
                    $event->test()->id(),
                    TimingExtension::fileFor($event->test(), $this->root),
                );
            }
        });

        $facade->registerSubscriber(new class($collector, $root) implements ErroredSubscriber
        {
            public function __construct(
                private readonly TimingCollector $collector,
                private readonly string $root,
            ) {}

            public function notify(Errored $event): void
            {
                $this->collector->terminated(
                    $event->test()->id(),
                    TimingExtension::fileFor($event->test(), $this->root),
                );
            }
        });

        $facade->registerSubscriber(new class($collector, $root) implements MarkedIncompleteSubscriber
        {
            public function __construct(
                private readonly TimingCollector $collector,
                private readonly string $root,
            ) {}

            public function notify(MarkedIncomplete $event): void
            {
                $this->collector->terminated(
                    $event->test()->id(),
                    TimingExtension::fileFor($event->test(), $this->root),
                );
            }
        });

        $facade->registerSubscriber(new class($flush) implements ExecutionFinishedSubscriber
        {
            public function __construct(private readonly Closure $flush) {}

            public function notify(ExecutionFinished $event): void
            {
                ($this->flush)();
            }
        });

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

        return Paths::canonical($test->file(), $root, allowOutside: true);
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
