# REQ-100: Set the flushed flag only after a successful write

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.95040
**Claimed at:** 2026-07-10T04:34:01Z
**Heartbeat:** 2026-07-10T04:34:01Z
<!-- claimed-end -->

**UR:** UR-016
**Status:** in-progress
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Size:** S
**Files:** src/Timing/TimingCollector.php, src/Timing/TimingExtension.php, tests/Unit/Timing/TimingCollectorTest.php
**Depends on:** REQ-099

## Task

`TimingCollector::flush()` sets `$this->flushed = true` (TimingCollector.php:70) *before* calling `$store->writePending(...)` (line 71). Both the ExecutionFinished flush and the register_shutdown_function backstop guard on `hasFlushed()`, so one transient write failure permanently loses the run's timings — the backstop can never retry. Move the flag set to after the write succeeds. `writePending` uses a fresh unique filename per attempt, so a retry cannot double-publish; a backstop retry that succeeds after a failed primary flush recovers the run's data.

## Context

Finding 17 (UR-016), verified CONFIRMED: `writePending` genuinely throws on transient conditions (Dirs::ensure RuntimeException on an unwritable dir, AtomicFile::write on short write/rename failure, ENOSPC, JsonException) and TimingExtension's catch warns-and-returns (REQ-082 nonfatal behavior, `.do-work/archive/REQ-082-nonfatal-timing-flush.md`), but the pre-set flag means the shutdown backstop hits the `hasFlushed()` guard and returns without retrying — even when a retry seconds later would succeed. Direct extension of REQ-082 whose fix was narrower than the bug class; cite it and preserve its warn-once behavior (two failures should not warn twice for the same run unless the retry also fails). Runs after REQ-099 since that REQ reworks the same flush path.

## Acceptance Criteria

- [ ] `flushed` becomes true only after `writePending` returns without throwing
- [ ] A first flush whose write throws leaves `hasFlushed()` false; a subsequent (backstop) flush retries the write and, on success, the run's timings are on disk
- [ ] A successful first flush still makes the backstop a no-op (no duplicate batch)
- [ ] The REQ-082 nonfatal contract holds: flush failures never abort the test run; warnings are emitted per failed attempt

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce the original bug first: store stub whose writePending throws once then succeeds — call flush() twice; assert the second call writes the batch (must fail pre-fix, where the second call is guarded out)
   - Expected: batch written on retry; hasFlushed() true only after success
2. **test** Successful first flush then second flush — assert exactly one batch written
   - Expected: no duplicates
3. **test** `./vendor/bin/pest --filter=TimingCollectorTest && ./vendor/bin/pest --filter=TimingCaptureTest`
   - Expected: all green
