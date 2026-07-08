# REQ-040: PHPUnit timing extension and end-to-end capture test

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.73078
**Claimed at:** 2026-07-08T20:44:19Z
**Heartbeat:** 2026-07-08T20:44:19Z
<!-- claimed-end -->

**UR:** UR-010
**Status:** in-progress
**Created:** 2026-07-09
**Layer:** integration
**Entry point:** `WARP_TIMINGS=1 ./vendor/bin/pest` with `RawPHP\Warp\Timing\TimingExtension` registered in `phpunit.xml`
**Terminal state:** A real child Pest run records per-test timings with project-relative file attribution into the configured timings directory, and timing capture leaves no trace when disabled.
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 3
**Size:** L
**Files:** src/Timing/TimingExtension.php, phpunit.xml, tests/Integration/Timing/TimingCaptureTest.php
**Depends on:** REQ-036, REQ-037, REQ-038, REQ-039

## Task

Implement plan **Task 5**: register a no-op-unless-enabled PHPUnit event extension, subscribe to preparation/finish/execution-finished events, resolve Pest file paths, flush timings, and add the real child-process capture integration test.

## Context

This is the first end-to-end timing path. It consumes the env switch, store, collector, and resolver REQs. The plan intentionally registers the extension before the class exists to produce the expected red; workers must follow the TDD sequence and then create the class to clear the boot failure.

## Acceptance Criteria

- [ ] `phpunit.xml` registers `RawPHP\Warp\Timing\TimingExtension`.
- [ ] `TimingExtension::bootstrap()` returns before registering subscribers when `WARP_TIMINGS` is disabled.
- [ ] With `WARP_TIMINGS=1`, a real Pest child process records many `tests/Unit/WarpModeTest.php` cases with non-negative durations.
- [ ] `TimingExtension::seconds()` converts PHPUnit telemetry `HRTime` to float seconds.
- [ ] Execution-finished and shutdown backstop flush paths are safe because collector flush is idempotent.

## Verification Steps

1. **test** `./vendor/bin/pest tests/Integration/Timing/TimingCaptureTest.php`
   - Expected: PASS for enabled and disabled timing-capture child-process cases.
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.

## Integration

**Reachability:** PHPUnit loads extensions from `phpunit.xml`; users enable the path with `WARP_TIMINGS=1` on normal Pest/PHPUnit invocations.

**Data dependencies:** Reads PHPUnit event telemetry, test ids, test class names, reported files, `getcwd()`, `WARP_TIMINGS`, and `WARP_TIMINGS_DIR`.

**Service dependencies:** Consumes `WarpMode::timingsEnabled()` from REQ-036, `TimingStore` from REQ-037, `TimingCollector` from REQ-038, and `TestFileResolver` from REQ-039.
