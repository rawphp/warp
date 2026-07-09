# REQ-081: Early-stopped timing runs flush incomplete

**UR:** UR-015
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Closure proof:** checkpoint_log:passed all 2 verification checkpoints passed; commit:c70a660
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Timing/TimingExtension.php, tests/Integration/Timing/TimingCaptureTest.php
**Depends on:**

## Task

Fix timing capture completeness for PHPUnit early-stop runs. When `stopOnFailure`, `stopOnDefect`, or `stopOnError` stops the selected run before all selected tests execute, `TimingExtension` must write the pending batch with `complete=false` even if PHPUnit still emits `ExecutionFinished`.

## Context

Confirmed finding 1: `hasRestrictedSelection()` only inspects static selection restrictions, so an early-stopped run can flush `complete=true` and `TimingStore::apply()` deletes stored timings for tests in the same file that never ran. Clarification: any configured early stop that terminates before the selected run completes is incomplete. Reuse REQ-073 semantics: method/group/path restrictions are incomplete, plain `--testsuite` remains complete, and this fix extends that model rather than replacing it.

## Acceptance Criteria

- [x] A child Pest/PHPUnit fixture with `stopOnFailure="true"` records only the tests that ran before the first failure, writes a pending batch with `complete: false`, and exits with the expected failing test status.
- [x] After merging an early-stopped batch over a seeded full-file timing store, sibling test IDs that did not run remain present with their previous `ms` values.
- [x] Existing restricted-run behavior still holds: method/group/path restrictions flush `complete=false`, while plain `--testsuite` and unrestricted successful runs flush `complete=true`.
- [x] Shutdown backstop behavior from REQ-074 is not weakened: an in-flight or fatal shutdown still does not supersede sibling timings.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="TimingCapture|TimingExtension|TimingStore"`
   - Expected: the suite passes, including a child-process regression where `stopOnFailure` leaves sibling stored timings intact after merge.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green; completeness behavior remains compatible with prior timing-store and shutdown-backstop tests.

## Outputs

- src/Timing/TimingExtension.php — Tracks selected versus finished test counts so actual stop-on early exits flush incomplete while fully observed stop-on runs flush complete.
- tests/Integration/Timing/TimingCaptureTest.php — Adds child-process regressions for successful stopOnFailure, actual stopOnFailure, stopOnDefect, and stopOnError timing completeness.
