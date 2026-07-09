<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Event;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use RawPHP\Warp\WarpMode;

final class TimingExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (! WarpMode::timingsEnabled()) {
            return;
        }

        $collector = new TimingCollector;
        $store = TimingStore::fromEnv();
        $root = (string) getcwd();

        $facade->registerSubscriber(new class($collector) implements PreparationStartedSubscriber
        {
            public function __construct(private readonly TimingCollector $collector) {}

            public function notify(PreparationStarted $event): void
            {
                $this->collector->started($event->test()->id(), TimingExtension::seconds($event));
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

                if (! $test->isTestMethod()) {
                    return;
                }

                /** @var TestMethod $test */
                $this->collector->finished(
                    $test->id(),
                    TestFileResolver::resolve($test->className(), $test->file(), $this->root),
                    TimingExtension::seconds($event),
                );
            }
        });

        $facade->registerSubscriber(new class($collector, $store) implements ExecutionFinishedSubscriber
        {
            public function __construct(
                private readonly TimingCollector $collector,
                private readonly TimingStore $store,
            ) {}

            public function notify(ExecutionFinished $event): void
            {
                $this->collector->flush($this->store, complete: true);
            }
        });

        // Backstop: paratest workers and fatally-interrupted runs may never
        // see ExecutionFinished; flush() is idempotent so both paths are safe.
        register_shutdown_function(static fn () => $collector->flush($store, complete: false));
    }

    /** Telemetry wall-clock as float seconds, monotonic within a run. */
    public static function seconds(Event $event): float
    {
        $time = $event->telemetryInfo()->time();

        return $time->seconds() + $time->nanoseconds() / 1_000_000_000;
    }
}
