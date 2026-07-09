# REQ-075: Shared atomic-write helper closes mergeToDisk short-write gap

**UR:** UR-013
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed checkpoints:2 commit:d2e5aac
**Criteria approved:** agent-drafted
**Priority:** 3
**Size:** M
**Files:** src/Support/AtomicFile.php, src/Timing/TimingStore.php, tests/Unit/Support/AtomicFileTest.php, tests/Unit/Timing/TimingStoreTest.php
**Depends on:**

## Task

`TimingStore::mergeToDisk()` (src/Timing/TimingStore.php:77) checks `file_put_contents() === false` only — a short/partial write on a near-full disk returns a positive byte count, and the truncated tmp is then `rename()`d over `timings.json`, wedging every later `warp shard`/`warp timings` on a decode error until the file is deleted by hand. `writePending()` already guards this (`$bytes < strlen($encoded)` at line 47, from REQ-068) — the two copies of the tmp-write + rename + unlink-on-failure sequence have drifted. Extract one shared atomic-write helper (e.g. `Support\AtomicFile::write(string $path, string $contents): void` — write to `$path.tmp`, verify full byte count, rename, unlink tmp and throw on any failure) and use it from BOTH `writePending()` and `mergeToDisk()`, so the guard exists exactly once.

## Context

Code-review finding #4 plus the paired reuse observation from the brief. This is the residual half of REQ-068 (which fixed writePending only) — read archived REQ-068 and REQ-048 first; the helper closes the drift class, not just the instance. `Support\FileLock` is the pattern precedent for a small focused Support helper. Exception messages fed through the helper must keep the existing `[warp] cannot write/publish ...` shapes asserted by current tests, or those assertions must be updated deliberately.

## Acceptance Criteria

- [x] A short write in `mergeToDisk()` (simulated: byte count below payload length) does NOT publish over `timings.json`; the tmp file is cleaned up and a `RuntimeException` is raised (reproduces then fixes the finding-#4 scenario).
- [x] `writePending()`'s existing short-write behavior (REQ-068 tests) still passes, now routed through the shared helper — no truncated pending batch is published.
- [x] Exactly one implementation of the tmp-write/verify/rename sequence remains in src/ (grep confirms `writePending` and `mergeToDisk` both call the helper and contain no inline `rename(` publish of their own).
- [x] A successful merge still atomically replaces `timings.json` (existing merge tests pass).

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="AtomicFile|TimingStore"` — Expected: all pass, including a new mergeToDisk short-write case asserting the truncated file never lands at timings.json.
2. **test** `./vendor/bin/pest` — Expected: full suite green (shared write path under SnapshotStore/CLI consumers).

## Outputs

- src/Support/AtomicFile.php — Shared atomic file writer with tmp write, full-byte verification, rename publish, tmp cleanup, and `RuntimeException` failure messages.
- src/Timing/TimingStore.php — Routes pending-batch and merged-timings writes through `AtomicFile`.
- tests/Unit/Support/AtomicFileTest.php — Covers successful atomic publish and short-write cleanup without replacing the target file.
- tests/Unit/Timing/TimingStoreTest.php — Adds `mergeToDisk()` short-write regression coverage and routes existing short-write simulation through the shared helper.
