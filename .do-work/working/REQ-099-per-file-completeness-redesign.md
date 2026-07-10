# REQ-099: Per-file event-driven completeness redesign

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.95040
**Claimed at:** 2026-07-10T03:59:49Z
**Heartbeat:** 2026-07-10T03:59:49Z
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
**Size:** L
**Files:** src/Timing/TimingExtension.php, src/Timing/TimingCollector.php, src/Timing/TimingStore.php, tests/Unit/Timing/TimingExtensionTest.php, tests/Unit/Timing/TimingCollectorTest.php, tests/Unit/Timing/TimingStoreTest.php, tests/Integration/Timing/TimingCaptureTest.php
**Depends on:** REQ-093

## Task

Replace the process-wide run-completeness flag (counters + stop-on sniffing + `error_get_last()` fatal heuristics) with **per-file event accounting**, per the question-gate design decision:

1. **Track per file:** tests enumerated for that file in this process vs tests that reached a terminal event. Subscribe to ALL terminal events — Finished, Skipped, Errored, MarkedIncomplete — not just Finished; this fixes the setUp-skip and .phpt in-flight leaks by construction (a skipped-before-preparation test still terminates its accounting entry).
2. **A file is complete** when every test enumerated for it in this process reached a terminal event.
3. **Batch payload carries per-file complete flags** (schema addition on top of REQ-093's root stamp — coordinate the VERSION bump).
4. **Supersede becomes per-file-when-that-file-complete** in `TimingStore::apply()`: a batch's entries for file F replace F's prior entries only when the batch marks F complete; otherwise upsert.
5. **Delete the superseded machinery:** `hasStopOnConfiguration()` (and its incomplete stop-on list), the process-wide `selectedTests`/`finishedTests` counters, the `shutdownHadFatalError()`/`error_get_last()` classification, and the process-level complete flag on the batch. A worker that saw only part of a file (paratest --functional slicing) never marks that file complete, so the paratest mutual-deletion and counter-overwrite bugs become structurally impossible.
6. The shutdown backstop still flushes; it simply writes whatever per-file accounting shows. Incomplete files upsert, complete files supersede — no run-level discriminator needed.

This intentionally supersedes the UR-013 completeness semantics recorded in decisions.md (superseding entry appended by capture) and is the deliberate one-design-fix exception to the UR-015 narrow-REQ precedent.

## Context

Findings 4, 5, 14, 15, 16 (UR-016), all verified CONFIRMED — five holes in one subsystem built by REQ-050/069/073/081, where prior point-fixes repeatedly left adjacent cases open: the stop-on list misses six PHPUnit flags (4); .phpt tests leak in-flight entries because only Finished is filtered on TestMethod (5); paratest --functional workers each flush complete=true for file slices and delete each other's halves (14, confirmed against vendored paratest source); executionStarted() overwrites selectedTests per file while finishedTests spans the worker (15); setUp/requirement skips never emit Finished so one skip poisons process-level completeness (16). Per-file event accounting kills the class: completeness is decided where the data lives (the file), from events (terminal notifications), not inferred process state.

## Acceptance Criteria

- [ ] Batch payload carries per-file complete flags; `TimingStore::apply()` supersedes per file only when that file is flagged complete, upserts otherwise; store VERSION coordinated with REQ-093
- [ ] A run halted by `--stop-on-warning` (or any stop-on flag) marks the interrupted file incomplete — its prior full timings survive the merge — while fully-terminated files still supersede (fixes finding 4 without any stop-on flag list)
- [ ] A suite containing `.phpt` tests and a test skipped in `setUp()` reaches end-of-run with zero in-flight entries; fully-run files are marked complete (fixes findings 5 and 16)
- [ ] Simulated paratest --functional slicing (two collectors each fed half of one file's tests, both flushed) merges to the union of both halves — neither batch deletes the other's entries (fixes finding 14)
- [ ] `hasStopOnConfiguration`, `shutdownHadFatalError`, and the process-wide selected/finished counters are deleted; no caller remains (grep confirms)
- [ ] A real subprocess test exercises the register_shutdown_function backstop in a process where ExecutionFinished never fires (test kills or exit()s mid-run) and asserts a pending batch exists with correct per-file flags — closing the untested-backstop gap
- [ ] Full suite green

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce finding 4 first: subprocess run with `--stop-on-warning` halting mid-file — assert the interrupted file's prior timings survive the subsequent merge (must fail pre-fix, where the batch is complete=true and supersedes)
   - Expected: interrupted file incomplete; prior data intact
2. **test** Suite fixture with a `.phpt` test and a setUp-skip — assert no in-flight entries at flush and correct per-file complete flags
   - Expected: zero leaks; completed files flagged complete
3. **test** Two-collector slicing simulation of one file merged sequentially — assert union survives
   - Expected: no mutual deletion
4. **runtime** Subprocess that `exit()`s mid-run (ExecutionFinished never fires) — assert the shutdown backstop wrote a pending batch and the mid-flight file is marked incomplete
   - Expected: batch exists; interrupted file incomplete
5. **test** `./vendor/bin/pest`
   - Expected: full suite green
