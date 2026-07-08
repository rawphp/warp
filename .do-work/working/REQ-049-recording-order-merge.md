# REQ-049: Merge pending batches in recording order, not pid-lexicographic order

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.21409
**Claimed at:** 2026-07-08T23:14:17Z
**Heartbeat:** 2026-07-08T23:14:17Z
<!-- claimed-end -->

**UR:** UR-011
**Status:** in-progress
**Created:** 2026-07-09
**Layer:** package
**Entry point:**
**Terminal state:**
**Parent:** REQ-046
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 3
**Size:** M
**Files:** src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php
**Depends on:** REQ-048

## Task

Make merge order track recording order (finding #5). Change the pending filename format from `{pid}-{randomhex}.json` (src/Timing/TimingStore.php:38) to embed a monotonic timestamp prefix, e.g. `{microtime-as-int}-{pid}-{randomhex}.json`, and change `mergePending()`'s ordering (currently plain `sort($pending)` at line 64) to sort by the numeric timestamp prefix (tie-break: full filename). Clean break: old-format files need no migration support (decisions.md 2026-07-05) — but decide explicitly what happens if one is encountered (skip with stderr warning is acceptable).

## Context

Review finding #5: pending filenames carry no timestamp, and lexicographic `sort()` doesn't even order numeric PIDs correctly (`10000-` < `999-`). When two recording runs accumulate before a merge (the README workflow persists `.warp/timings` wholesale), an older run's batch can sort after a newer run's, and apply()'s supersede-by-file semantics let stale timings overwrite fresh ones — shard plans get built from outdated data and deleted tests are resurrected.

## Acceptance Criteria

- [ ] Pending filenames begin with a sortable numeric timestamp; two batches written in sequence produce filenames whose timestamp order matches write order.
- [ ] A test writes batch A (older timestamp, covering FooTest at 5000ms) and batch B (newer timestamp, covering FooTest at 50ms) with A's filename lexicographically smaller AND another case where A's is larger — after merge, FooTest is 50ms in both cases.
- [ ] Old-format (non-timestamp-prefixed) pending files are handled by an explicit documented rule (skip + stderr warning, or merge-first), asserted by a test — never a crash.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Timing/TimingStoreTest.php`
   - Expected: recording-order supersede tests pass for both lexicographic arrangements.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green.

## Integration

**Reachability:** Same surfaces as REQ-048: `writePending()` via `TimingCollector::flush()` (src/Timing/TimingCollector.php), `mergePending()` via `TimingStore::load()`.

**Data dependencies:** `pending/*.json` filename format (clean break) and `timings.json` supersede semantics.

**Service dependencies:** Builds directly on REQ-048's rewritten pending I/O in src/Timing/TimingStore.php (hard dependency).
