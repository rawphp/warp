# REQ-104: Locked single-pass read snapshot with lockless fallback

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.dw17
**Claimed at:** 2026-07-10T06:27:19Z
**Heartbeat:** 2026-07-10T06:27:19Z
<!-- claimed-end -->

**UR:** UR-017
**Status:** in-progress
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Timing/TimingStore.php, src/Cli/ShardCommand.php, tests/Unit/Timing/TimingStoreTest.php, tests/Unit/Cli/ShardCommandTest.php
**Depends on:**

## Task

Fix the reader/merger race (finding 2) and the double-parse TOCTOU (finding 17) with one mechanism:

1. Introduce a single read snapshot in TimingStore: one method that scans pending/ and parses timings.json ONCE, returning both the merged totals and the stored root. `storedRoot()`, `load()`, and `fileTotals()` all consume the same snapshot within one command invocation (memoize per instance or return a snapshot object) — today ShardCommand's `storedRoot()` + `fileTotals()` calls each independently rescan pending/ and re-parse every batch, and the two passes can observe different store states.
2. Acquire merge.lock around the snapshot read so a concurrent `warp merge` cannot interleave (pre-fix: shard A reads pre-merge state with vanishing batches while shard B reads post-merge state → divergent LPT plans → a test file assigned to two shards or to none).
3. **Lockless fallback (UR-017 question-gate decision):** when the lock file cannot be created — read-only timings dir, the standing UR-011 read-only CI-artifact-restore guarantee — fall back to today's lockless read with its vanished-batch tolerance, without warning noise for the plain read-only-restore case. The read path must never create or modify files besides the lock attempt itself.

## Context

Review finding 2 (severe: silently unrun tests across a shard matrix) — reachability already settled by the UR-016 decision superseding UR-012 ("pending-at-shard-time is reachable: local parallel runs, misconfigured CI"). Finding 17 folded in because the single-snapshot design is the same mechanism. The lockless fallback preserves decisions.md UR-011: "warp shard/timings are read-only (in-memory pending overlay); read-only CI artifact restores must work" — FileLock opens its lock file for writing, so unconditional locking would break exactly that guarantee.

## Acceptance Criteria

- [ ] One shard invocation scans pending/ and parses each batch exactly once; storedRoot and fileTotals come from the same snapshot (assertable via injected filesystem counters or a spy on the read path)
- [ ] With a writable timings dir, the snapshot read holds merge.lock: a merge running concurrently cannot produce a snapshot that mixes pre-merge pending with post-merge timings.json (test via lock-held assertion or interleaving simulation)
- [ ] With a read-only timings dir (chmod 555), `warp shard` still succeeds using the lockless fallback and produces the same plan as the writable case for identical store contents
- [ ] The read path creates no files in the timings dir other than the lock attempt (read-only dir remains byte-identical after shard)
- [ ] All existing TimingStore and ShardCommand tests green

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce finding 17/2 first: assert pending/ is scanned once per shard invocation and storedRoot+fileTotals reflect one consistent state (must fail pre-fix with two independent scans)
   - Expected: red-then-green; scan-count assertion
2. **test** Lock coverage: while merge.lock is held by another handle, the snapshot read blocks or serializes rather than reading through
   - Expected: no interleaved snapshot; handoff lock-acquire → snapshot localized
3. **test** Read-only restore: chmod 555 timings dir → shard exits 0, duration-balanced plan, directory contents unchanged
   - Expected: lockless fallback works; UR-011 guarantee preserved
4. **test** `./vendor/bin/pest --filter=TimingStoreTest && ./vendor/bin/pest --filter=ShardCommandTest`
   - Expected: all green
