<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

use Closure;
use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Event\TestSuite\Loaded;
use PHPUnit\Event\TestSuite\LoadedSubscriber;
use PHPUnit\Runner\Extension\Facade;

/**
 * Wire TimingCollector event handlers onto a PHPUnit extension facade.
 * {@see TimingExtension} remains the public Extension entry; this owns the
 * per-event subscriber bodies so bootstrap stays a thin gate.
 *
 * @internal Package timing-extension plumbing; not host-facing.
 */
final class ExtensionRegistrar
{
    public static function register(
        Facade $facade,
        TimingCollector $collector,
        string $root,
        Closure $flush,
    ): void {
        // Terminal-outcome handlers shared by closure-based subscribers so the
        // (TimingCollector, string $root) constructor and the fileFor()+collector
        // call body are not copy-pasted per event (finding 16). Skipped and
        // MarkedIncomplete both just close an accounting entry; Errored
        // additionally records its own duration for the never-prepared case.
        $terminate = static function (Test $test) use ($collector, $root): void {
            $collector->terminated($test->id(), EventTelemetry::fileFor($test, $root));
        };
        $errored = static function (Errored $event) use ($collector, $root): void {
            // Errored records the telemetry duration for the never-prepared case
            // (finding 5, REQ-105): Test\Finished never fires for it, so these
            // seconds are the only weight the file would otherwise get.
            $collector->errored(
                $event->test()->id(),
                EventTelemetry::fileFor($event->test(), $root),
                EventTelemetry::seconds($event),
            );
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
                        EventTelemetry::fileFor($test, $this->root),
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
                    EventTelemetry::seconds($event),
                    EventTelemetry::fileFor($event->test(), $this->root),
                );
            }
        });

        // Test\Prepared fires only once setUp/hooks succeed - the same condition
        // PHPUnit's own wasPrepared() gates Test\Finished on. Tracking it lets the
        // Errored subscriber tell "errored before being prepared" (Finished will
        // never fire; needs its duration from Errored's own telemetry) apart from
        // "prepared, errored after running" (Finished still fires; recording here
        // too would double-count it) - see finding 5.
        $facade->registerSubscriber(new class($collector) implements PreparedSubscriber
        {
            public function __construct(private readonly TimingCollector $collector) {}

            public function notify(Prepared $event): void
            {
                $this->collector->prepared($event->test()->id());
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
                $file = EventTelemetry::fileFor($test, $this->root);

                if ($test->isTestMethod()) {
                    $this->collector->finished($test->id(), $file, EventTelemetry::seconds($event));

                    return;
                }

                // Non-method tests (.phpt) emit Finished but carry no timing;
                // still close their accounting entry so the file can complete.
                $this->collector->terminated($test->id(), $file);
            }
        });

        // Every other terminal outcome closes an accounting entry: setUp/
        // requirement skips and incomplete markings never carry a duration here
        // (Test\Finished never fires for them either, but they aren't gated on
        // wasPrepared() the same way - out of this fix's scope, findings 5, 16).
        // Errored is the exception: it records a duration itself for the
        // never-prepared case (finding 5), since Finished never fires for it.
        $facade->registerSubscriber(new class($terminate) implements SkippedSubscriber
        {
            public function __construct(private readonly Closure $terminate) {}

            public function notify(Skipped $event): void
            {
                ($this->terminate)($event->test());
            }
        });

        $facade->registerSubscriber(new class($errored) implements ErroredSubscriber
        {
            public function __construct(private readonly Closure $errored) {}

            public function notify(Errored $event): void
            {
                ($this->errored)($event);
            }
        });

        $facade->registerSubscriber(new class($terminate) implements MarkedIncompleteSubscriber
        {
            public function __construct(private readonly Closure $terminate) {}

            public function notify(MarkedIncomplete $event): void
            {
                ($this->terminate)($event->test());
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
    }
}
