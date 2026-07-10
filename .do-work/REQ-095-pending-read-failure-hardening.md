# REQ-095: Pending-batch read-failure hardening in load and merge

**UR:** UR-016
**Status:** backlog
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Size:** M
**Files:** src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php, tests/Unit/Cli/MergeCommandTest.php
**Depends on:**

## Task

Fix the three data-loss paths in `TimingStore::mergedWithPending()` (the guard at TimingStore.php:168 and its fallthrough) by distinguishing the failure modes explicitly:

1. **Unreadable-but-existing pending file (any mode):** `file_get_contents === false && is_file($path)` must never fall through to `json_decode((string) false)`. Skip the batch with a stderr warning, do NOT classify it as junk, do NOT add it to `$mergedPending` (so merge never unlinks it) — it stays on disk for retry on the next merge. (Finding 3.)
2. **Vanished file during merge (`cleanupJunk=true`, `!is_file`):** the current reset branch re-reads `readMerged()` — discarding batches already applied this pass — while `$mergedPending` keeps the applied batches queued for unlink, so an intact batch is deleted after publishing a timings.json that lacks its data. Under the merge lock a pending file cannot have been legitimately folded in by a concurrent merge, so do not reset: skip the vanished path with a warning and keep the accumulated `$tests`. (Finding 18.)
3. **Failed read during load (`cleanupJunk=false`):** the reset branch discards all batches already applied in the loop with no warning. Replace reset-and-continue with skip-the-failed-batch-and-warn, preserving applied batches. (Finding 2 — full fix per the question-gate decision re-litigating UR-012: pending-at-shard-time is reachable via local parallel runs and misconfigured CI.)

All three warnings go through the store's warning channel (kept compatible with REQ-101's stream threading).

## Context

Findings 2, 3, 18 (UR-016), all verified CONFIRMED — three distinct failure paths sharing the same ~30 lines of merge machinery, grouped per the UR-012/UR-013 subsystem-grouping precedent. Finding 3: a transiently unreadable batch (EACCES from CI uid mismatch, EIO) is misclassified as undecodable junk and permanently unlinked. Finding 18: a batch deleted externally mid-merge triggers a reset that silently drops already-applied batches yet still unlinks them. Finding 2: the same reset on the load path makes shard machines compute divergent plans, so files get double-run or skipped. Supersedes the 2026-07-09 UR-012 decisions.md line that dropped finding 2's scenario as unreachable (superseding entry appended by capture).

## Acceptance Criteria

- [ ] An existing-but-unreadable pending batch during `mergeToDisk` is skipped with a warning, survives on disk after the merge, and its data is absent from (not corrupted into) the published timings.json
- [ ] A pending batch that vanishes between glob and read during `mergeToDisk` does not cause already-applied batches to be discarded; the published timings.json contains every successfully-applied batch, and only actually-merged batch files are unlinked
- [ ] A failed pending read during `load()` skips only the failed batch, keeps all previously-applied batches in the returned totals, and emits a stderr warning (no silent divergence)
- [ ] Undecodable-JSON handling (genuinely corrupt content that reads successfully) is unchanged: warned, junk-classified, and cleaned only in `mergeToDisk`
- [ ] All existing TimingStore and MergeCommand tests remain green

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce finding 3 first: a pending file made unreadable (chmod 000) during `mergeToDisk` — assert it survives the merge on disk and a warning fires (must fail pre-fix, where it gets unlinked)
   - Expected: batch file still present post-merge, warning emitted, timings.json published without corruption
2. **test** Reproduce finding 18: batches B1, B2 where B2 is unlinked after glob but before read — assert B1's data is in the published timings.json and B1's file was unlinked as merged
   - Expected: B1 contributions present; no intact batch lost
3. **test** Reproduce finding 2: load() with batches A, B, C where C is unreadable — assert fileTotals() includes A and B and a warning fires
   - Expected: only C skipped; A/B totals present
4. **test** `./vendor/bin/pest --filter=TimingStoreTest && ./vendor/bin/pest --filter=MergeCommandTest`
   - Expected: all green
