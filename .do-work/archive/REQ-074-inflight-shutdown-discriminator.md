# REQ-074: In-flight discriminator for shutdown-backstop completeness

**UR:** UR-013
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed checkpoints:2 commit:6e9f7d1
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Timing/TimingCollector.php, src/Timing/TimingExtension.php, tests/Unit/Timing/TimingCollectorTest.php, tests/Integration/Timing/TimingCaptureTest.php
**Depends on:** REQ-072, REQ-073

## Task

The shutdown backstop (src/Timing/TimingExtension.php:84) classifies any non-fatal abnormal exit as a COMPLETE run: a test (or code under test) calling `exit()`/`die()` leaves no fatal error record, so `$flush(!shutdownHadFatalError())` writes the collector's partial subset as `complete=true` — on merge, `apply()` supersedes each touched file's full timing history down to the crash subset. Add an in-flight discriminator: `TimingCollector` knows whether a test was started but never finished (`$startedAt` non-empty → abnormal mid-test exit; empty → natural end). Expose it (e.g. `hasInFlight(): bool`) and have the shutdown backstop flush `complete=false` when a test is in flight, `complete=true` otherwise. Also remove the dead `|| ! self::shutdownHadFatalError()` term at src/Timing/TimingExtension.php:101 — it can never demote a `complete=true` flush and falsely implies completeness is re-validated at flush time.

**Decided semantics (clarified at question gate):** `exit()` mid-test → incomplete; paratest worker natural end (empty in-flight set, no `ExecutionFinished`) → complete. This preserves REQ-069's guarantee that always-parallel runs still prune stale IDs.

## Context

Code-review finding #2. CRITICAL: the current behavior IS the accepted REQ-069 fix ("normal shutdown backstop flushes are complete unless PHP is shutting down after a fatal error") — do not simply revert shutdown flushes to incomplete, or REQ-069's paratest stale-ID accumulation bug returns. Read archived REQ-069 and REQ-050 before changing anything; the in-flight discriminator is the reconciliation, and the fatal-error check remains as an additional incomplete trigger. Note the reconciliation in the commit body. Depends on REQ-072 (same collector/flush machinery — flag ordering must land first) and REQ-073 (same extension flush path and shared test file).

## Acceptance Criteria

- [x] A shutdown-backstop flush with an in-flight test (started, never finished — simulating `exit()` mid-test) writes `complete: false`; after merge, previously-stored timings for the interrupted file's other tests survive (reproduces then fixes the finding-#2 scenario).
- [x] A shutdown-backstop flush with no in-flight test and no fatal error (paratest worker natural end) still writes `complete: true`, and stale-ID pruning for fully-observed files still fires across repeated merges (REQ-069 regression guard — existing tests pass).
- [x] A shutdown-backstop flush after a fatal error still writes `complete: false` (existing REQ-050 crash semantics unchanged).
- [x] The `|| ! self::shutdownHadFatalError()` term in `TimingExtension::flush()` is removed; the completeness decision is made once at the backstop/subscriber call sites.

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="TimingCollector|TimingCapture|TimingExtension"` — Expected: all pass, including new in-flight-discriminator cases for the exit()-mid-test and natural-worker-end paths.
2. **test** `./vendor/bin/pest` — Expected: full suite green (touches the same supersede machinery as REQ-069/REQ-073).

## Outputs

- src/Timing/TimingCollector.php — Adds `hasInFlight()` to expose unfinished started tests.
- src/Timing/TimingExtension.php — Uses a shutdown-backstop completeness discriminator for restricted, fatal, and in-flight shutdowns.
- tests/Unit/Timing/TimingCollectorTest.php — Covers collector in-flight state transitions.
- tests/Integration/Timing/TimingCaptureTest.php — Covers in-flight incomplete shutdown flushes, natural complete shutdown flushes, and fatal shutdown incompleteness.
