# REQ-037: Persistent per-test timing store

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.73078
**Claimed at:** 2026-07-08T20:31:10Z
**Heartbeat:** 2026-07-08T20:31:10Z
<!-- claimed-end -->

**UR:** UR-010
**Status:** in-progress
**Created:** 2026-07-09
**Layer:** package
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 3
**Size:** M
**Files:** src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php
**Depends on:**

## Task

Implement plan **Task 2**: create `RawPHP\Warp\Timing\TimingStore` with lock-free pending batch writes, flock-serialized deterministic merge, file-replacement semantics, `load()`, `fileTotals()`, `aggregate()`, and `fromEnv()`.

## Context

This is the durable artifact layer for S3. It is consumed by the collector, extension, CLI, and bench harness, so corrupt pending files, malformed entries, and stale entries from renamed/deleted tests must be handled exactly as the plan describes. `RawPHP\Warp\Db\Dirs` already exists for filesystem setup and cleanup.

## Acceptance Criteria

- [ ] Empty loads and empty pending writes do not create directories.
- [ ] Pending batches merge into `timings.json`, consumed pending files are removed, and corrupt pending files are ignored and deleted.
- [ ] A fresh batch for a file supersedes all previous entries for that file while preserving other files.
- [ ] Malformed pending entries are dropped, unknown merged versions load as empty, and `aggregate()` returns path-sorted file totals.
- [ ] `TimingStore::fromEnv()` honors `WARP_TIMINGS_DIR` and defaults to `.warp/timings` under the invocation cwd.

## Verification Steps

1. **test** `./vendor/bin/pest tests/Unit/Timing/TimingStoreTest.php`
   - Expected: PASS for all plan Task 2 store tests.
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.

## Integration

**Reachability:** Used by `TimingCollector::flush()` in REQ-038, `TimingExtension` in REQ-040, `warp timings`/`warp shard` in REQ-043, and `bench/shard-spread.php` in REQ-044.

**Data dependencies:** Reads and writes the portable `.warp/timings` artifact, or `WARP_TIMINGS_DIR` when set.

**Service dependencies:** Reuses existing `RawPHP\Warp\Db\Dirs` from `src/Db/Dirs.php`.
