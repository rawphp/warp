# REQ-040: PHPUnit timing extension and end-to-end capture test

**UR:** UR-010
**Status:** done
**Created:** 2026-07-09
**Layer:** integration
**Entry point:** `WARP_TIMINGS=1 ./vendor/bin/pest` with `RawPHP\Warp\Timing\TimingExtension` registered in `phpunit.xml`
**Terminal state:** A real child Pest run records per-test timings with project-relative file attribution into the configured timings directory, and timing capture leaves no trace when disabled.
**Parent:**
**Closure proof:** PHPUnit extension registered and verified by real child Pest timing capture when enabled and no-trace behavior when disabled.
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

- [x] `phpunit.xml` registers `RawPHP\Warp\Timing\TimingExtension`.
- [x] `TimingExtension::bootstrap()` returns before registering subscribers when `WARP_TIMINGS` is disabled.
- [x] With `WARP_TIMINGS=1`, a real Pest child process records many `tests/Unit/WarpModeTest.php` cases with non-negative durations.
- [x] `TimingExtension::seconds()` converts PHPUnit telemetry `HRTime` to float seconds.
- [x] Execution-finished and shutdown backstop flush paths are safe because collector flush is idempotent.

## Verification Steps

1. **test** `./vendor/bin/pest tests/Integration/Timing/TimingCaptureTest.php`
   - Expected: PASS for enabled and disabled timing-capture child-process cases.
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.

## Integration

**Reachability:** PHPUnit loads extensions from `phpunit.xml`; users enable the path with `WARP_TIMINGS=1` on normal Pest/PHPUnit invocations.

**Data dependencies:** Reads PHPUnit event telemetry, test ids, test class names, reported files, `getcwd()`, `WARP_TIMINGS`, and `WARP_TIMINGS_DIR`.

**Service dependencies:** Consumes `WarpMode::timingsEnabled()` from REQ-036, `TimingStore` from REQ-037, `TimingCollector` from REQ-038, and `TestFileResolver` from REQ-039.

## Outputs

- `phpunit.xml` — registered `RawPHP\Warp\Timing\TimingExtension` as a PHPUnit extension.
- `src/Timing/TimingExtension.php` — event extension that subscribes only when `WARP_TIMINGS` is enabled, records per-test timings, resolves source files, and flushes via execution-finished plus shutdown backstop.
- `tests/Integration/Timing/TimingCaptureTest.php` — child-process capture coverage for enabled timing artifact creation and disabled no-trace behavior.

## Verification Evidence

- `./vendor/bin/pest tests/Integration/Timing/TimingCaptureTest.php` — PASS, 2 tests / 110 assertions in the worktree and again after merge in the base checkout.
- `./vendor/bin/pest` — PASS, 160 tests / 376 assertions before Pint formatting.
- `./vendor/bin/pint --dirty` — PASS after formatting `src/Timing/TimingExtension.php`.
