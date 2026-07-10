# REQ-105: Record telemetry duration for errored-unprepared tests

**UR:** UR-017
**Status:** backlog
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Timing/TimingExtension.php, src/Timing/TimingCollector.php, tests/Unit/Timing/TimingExtensionTest.php, tests/Unit/Timing/TimingCollectorTest.php, tests/Integration/Timing/TimingCaptureTest.php
**Depends on:**

## Task

Fix finding 5: a test whose setUp throws never fires `Test\Finished` (PHPUnit gates Finished on `wasPrepared`), so no duration is recorded — but the Errored subscriber still counts it toward per-file completeness, the file gets flagged complete, and `TimingStore::apply()` supersedes the file's prior real timings with nothing. A persistently slow-then-erroring file (60s DB timeout in setUp) is permanently weighted ~0ms.

Fix: when the Errored event fires for a test that was **never prepared** (no PreparationStarted seen for its id, i.e. Finished will never fire), record a duration from the Errored event's telemetry `seconds()` before calling `terminated()`. Gate strictly on the never-prepared condition — prepared-but-failing tests already record via Finished, and unconditional recording would double-count (UR-017 question-gate decision). Pin down in code/comment which duration telemetry `seconds()` measures (time since previous telemetry event) and why that approximates the setUp cost for this path.

## Context

Review finding 5, CONFIRMED against vendor TestRunner.php (`if ($test->wasPrepared())` gates Finished emission; testErrored fires regardless). This is a hole in the REQ-099 per-file completeness design (UR-016): completeness accounting is correct, but the supersede-on-complete semantics drop real weight when a terminal event carries no duration. Ideate flagged the double-count edge explicitly — the no-double-record test below is the guard.

## Acceptance Criteria

- [ ] A test that errors before preparation (setUp throws) records a nonzero duration from Errored telemetry, and its file's merged timing reflects that duration instead of dropping to zero weight
- [ ] A prepared test that fails/errors after running records exactly one duration (via Finished), not two — assert no double-record for the prepared-erroring path
- [ ] Per-file completeness accounting is unchanged: the file still completes when every enumerated test terminates
- [ ] Integration: a child PHPUnit run where one class's setUp always throws produces a pending batch whose entry for that file carries the telemetry-derived duration
- [ ] All existing timing tests green

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce finding 5 first: file with prior real timings + a run where all its tests error in setUp → assert merged totals keep nonzero weight for the file (must fail pre-fix: weight drops to zero after supersede)
   - Expected: red-then-green
2. **test** No-double-record: a prepared test that errors after preparation records exactly one duration entry
   - Expected: single entry; handoff Errored-subscriber → collector localized
3. **test** Integration child-run with always-throwing setUp: pending batch contains telemetry duration for the file
   - Expected: nonzero ms in batch payload
4. **test** `./vendor/bin/pest --filter=Timing`
   - Expected: all green
