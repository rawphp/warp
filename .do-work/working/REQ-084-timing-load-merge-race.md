# REQ-084: Timing reads do not drop batches during merge

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.82488
**Claimed at:** 2026-07-09T20:37:07Z
**Heartbeat:** 2026-07-09T20:51:28Z
<!-- claimed-end -->
**UR:** UR-015
**Status:** in-progress
**Created:** 2026-07-09
**Layer:** none
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php
**Depends on:**

## Task

Harden `TimingStore::load()`, `fileTotals()`, and pending-overlay reads so a concurrent `mergeToDisk()` cannot cause a pending batch to be silently dropped from the totals.

## Context

Confirmed finding 4: `load()` reads `timings.json` and pending batches without coordinating with `mergeToDisk()`, so a pending file unlinked after publish can be read as empty/undecodable and skipped. Clarification and standing decision: preserve read-only `warp shard/timings`; disk cleanup stays under explicit `warp merge`, and shard-time reads must not delete pending files.

## Acceptance Criteria

- [x] A regression test simulates `load()`/`fileTotals()` racing with `mergeToDisk()` and proves a pending batch is either included from the pre-merge view or from the published `timings.json`, never silently omitted.
- [x] A pending file that disappears during a read is not reported as an undecodable junk batch and does not reduce the computed file totals.
- [x] `load()` and `fileTotals()` remain read-only: they do not delete pending files, do not clean junk batches, and do not publish `timings.json`.
- [x] Existing explicit `mergeToDisk()` cleanup semantics from REQ-076 still hold: junk deletion and unlink warnings remain merge-only behavior.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="TimingStore"`
   - Expected: timing-store tests pass, including a deterministic race simulation where file totals include the racing batch and read-only overlay behavior is unchanged.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green; shard/timings read paths and merge cleanup paths remain compatible.
