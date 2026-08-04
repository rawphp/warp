<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Event;
use RawPHP\Warp\Support\Paths;

/**
 * Pure helpers for turning PHPUnit events into timing keys and wall-clock
 * seconds. Used by {@see ExtensionRegistrar} and thin wrappers on
 * {@see TimingExtension}; keeps the Extension entry free of resolver math.
 *
 * @internal Package timing-extension plumbing; not host-facing.
 */
final class EventTelemetry
{
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
}
