# REQ-082: TimingExtension flush failures are non-fatal

**UR:** UR-015
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Closure proof:** checkpoint_log:passed all 2 verification checkpoints passed; commit:4bcff27
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Timing/TimingExtension.php, src/Timing/TimingCollector.php, tests/Integration/Timing/TimingCaptureTest.php
**Depends on:**

## Task

Make `TimingExtension` treat timing-write failures as non-fatal telemetry failures during test execution. Exceptions from the test-run flush paths must warn once to stderr, prevent shutdown retry/fatal behavior, and must not turn an otherwise green suite red.

## Context

Confirmed finding 2: `flush()` lets `TimingStore::writePending()` failures escape from both the `ExecutionFinished` subscriber and the shutdown backstop. Clarification: `TimingExtension` flush failures are non-fatal for all test-run flush paths; explicit user commands such as `warp merge` can still fail when their direct timing read/write work fails.

## Acceptance Criteria

- [x] A child test run with `WARP_TIMINGS=1` and an unwritable timings directory exits 0 when the selected tests pass.
- [x] The same child run writes one clear `[warp]` warning to stderr naming the timing flush failure.
- [x] The shutdown backstop does not retry the same failed flush and does not produce a fatal shutdown error or exit 255.
- [x] Explicit timing-store operations outside `TimingExtension` retain their current failure behavior; this REQ only changes test-run telemetry flush handling.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="TimingCapture|TimingExtension"`
   - Expected: all timing-extension tests pass, including a child-process read-only timings-dir scenario whose test result remains green and whose stderr contains a single `[warp]` warning.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green; timing-store command failure tests still assert direct command failures where appropriate.

## Outputs

- src/Timing/TimingExtension.php — Converts test-run timing flush exceptions into a single non-fatal stderr warning.
- src/Timing/TimingCollector.php — Consumes a flush attempt before writing so shutdown does not retry failed telemetry writes.
- tests/Integration/Timing/TimingCaptureTest.php — Adds a child-process regression for non-fatal timing flush failure behavior.
- tests/Unit/Timing/TimingCollectorTest.php — Updates collector coverage for one-shot failed flush attempts.
