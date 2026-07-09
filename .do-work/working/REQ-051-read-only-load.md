# REQ-051: Read-only load — consumers overlay pending in memory; explicit mergeToDisk()

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.21409
**Claimed at:** 2026-07-09T00:03:26Z
**Heartbeat:** 2026-07-09T00:03:26Z
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
**Priority:** 2
**Size:** M
**Files:** src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php
**Depends on:** REQ-047, REQ-050

## Task

Split `TimingStore`'s read and write paths (finding #3, per the UR-011 clarified contract):

1. `load()` becomes strictly read-only: it reads `timings.json`, overlays any pending batches **in memory** (using the recording-order + completeness semantics from REQ-049/REQ-050), and returns the merged view. It must not open `merge.lock`, must not rewrite `timings.json`, and must not unlink anything. Note the current code opens the lock BEFORE checking whether pending is empty (src/Timing/TimingStore.php:49 vs :60) — all of that leaves the read path.
2. Add an explicit `mergeToDisk()` method that performs the durable merge (rewrite `timings.json`, delete merged pending files) under `Support\FileLock::withLock()` (REQ-047), replacing the inline lock choreography in `mergePending()`.

## Context

Review finding #3: `warp shard` performs destructive writes on its read path on every shard machine; a read-only restored CI artifact makes fopen(merge.lock) fail → exit 2 → (with the README guard) all shards silently skip their tests. Clarified contract: `warp shard`/`warp timings` never write; merging to disk happens only in an explicit `warp merge` step (REQ-052). Over-cap finding C3 (duplicated lock choreography) is resolved here by consuming REQ-047's helper.

## Acceptance Criteria

- [ ] `load()` on a store directory that is entirely read-only (chmod 0555, containing both `timings.json` and unmerged `pending/*.json`) succeeds and returns the overlaid merged view — no exception, no writes, no lock file created.
- [ ] `load()` leaves `pending/*.json` files in place (asserted after load).
- [ ] `mergeToDisk()` rewrites `timings.json` with the merged view, deletes merged pending files, and serializes concurrent callers via `FileLock::withLock()`; the inline fopen/flock code in TimingStore is deleted.
- [ ] In-memory overlay and `mergeToDisk()` produce identical merged data for the same inputs (property asserted by a test running both against the same fixture).

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Timing/TimingStoreTest.php`
   - Expected: read-only-directory load test and mergeToDisk equivalence test pass.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green.

## Integration

**Reachability:** `load()` is called by `ShardCommand::run()` and `TimingsCommand::run()` (src/Cli/ShardCommand.php:47, src/Cli/TimingsCommand.php:30); `mergeToDisk()` gains its CLI caller in REQ-052 (`warp merge`).

**Data dependencies:** `timings.json` and `pending/*.json` under the store dir; `merge.lock` now touched only by `mergeToDisk()`.

**Service dependencies:** Consumes `Support\FileLock` from REQ-047; builds on REQ-050's apply semantics (hard dependencies).
