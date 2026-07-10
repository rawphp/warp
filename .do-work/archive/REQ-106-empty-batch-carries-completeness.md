# REQ-106: writePending must persist completeness even when no durations were recorded


**UR:** UR-017
**Status:** done
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed commit:d96057c — 374 pest passed, pint clean
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php, tests/Integration/Timing/TimingCaptureTest.php
**Depends on:**

## Task

Fix finding 6: `TimingStore::writePending()` returns early when `$tests === []`, silently discarding the complete-files map. A run whose tests all skipped (or all errored — rarer after REQ-105) produces `tests=[]` with `completeFiles={F:true}`; the early return drops the batch, so `apply()`'s supersede path never runs and F's stale timings persist forever (e.g. a fully-skipped class keeps its old 30s weight and skews every future `warp shard`).

Fix: write the batch whenever there is anything to say — durations OR completeness flags. Only skip the write when both are empty. `apply()` already handles a complete file with no new entries (unsets stale entries); confirm and cover that path.

## Context

Review finding 6, CONFIRMED — TimingCollector::flush() genuinely produces tests=[] + non-empty completeFiles for all-skipped files (paratest per-file workers, `WARP_TIMINGS=1 phpunit tests/FooTest.php`). Second hole in the REQ-099 per-file completeness design (UR-016), sibling of REQ-105. Interaction noted at ideate: REQ-105 makes the all-errored case record durations, but the all-skipped case remains and still requires this fix.

## Acceptance Criteria

- [x] A flush with tests=[] and non-empty completeFiles writes a pending batch containing the completeness map
- [x] Merging that batch supersedes (removes) the file's stale timing entries from the artifact
- [x] A flush with tests=[] AND completeFiles=[] still skips the write (no empty junk batches)
- [x] Integration: a child PHPUnit run of a fully-skipped class whose file has prior recorded timings ends, after `warp merge`, with the stale entries gone
- [x] All existing TimingStore tests green

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce finding 6 first: seed timings.json with 30s for FooTest.php, flush tests=[] + complete={FooTest.php:true}, merge → assert stale entries removed (must fail pre-fix: batch discarded, 30s persists)
   - Expected: red-then-green; handoff flush → writePending → apply localized
2. **test** Both-empty flush writes nothing to pending/
   - Expected: no batch file created
3. **test** Integration: fully-skipped class child-run + merge clears prior weight
   - Expected: file absent from merged totals
4. **test** `./vendor/bin/pest --filter=TimingStoreTest`
   - Expected: all green

## Outputs

- src/Timing/TimingStore.php — `writePending()` now skips the write only when both `$tests` and `$completeFiles` are empty (was: early return whenever `$tests===[]`), so an all-skipped/all-errored file's completeness reaches `apply()` and supersedes its stale entries
- tests/Unit/Timing/TimingStoreTest.php — both-empty no-op guard + finding-6 reproduction (empty tests + non-empty completeFiles writes a superseding batch)
- tests/Integration/Timing/TimingCaptureTest.php — integration: real fully-skipped PHPUnit child run, post-merge stale timings removed
