# REQ-094: Absolutize WARP_TIMINGS_DIR at construction

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.95040
**Claimed at:** 2026-07-10T02:57:30Z
**Heartbeat:** 2026-07-10T02:57:30Z
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
**Size:** S
**Files:** src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php
**Depends on:**

## Task

`TimingStore::fromEnv()` stores a relative `WARP_TIMINGS_DIR` verbatim (TimingStore.php:26) — the REQ-089 `getcwd()` absolutization covers only the unset-env fallback branch. Absolutize an env-supplied relative dir against `getcwd()` at construction time, so every later use (including `Dirs::ensure` / `writePending` in the shutdown-flush backstop) resolves to the same directory regardless of subsequent `chdir()`.

## Context

Finding 9 (UR-016), verified CONFIRMED: with `WARP_TIMINGS_DIR=.warp/timings`, a `chdir()` that survives past test execution (tearDownAfterClass, bootstrap, another shutdown handler, or a fatal that skips PHPUnit's runBare cwd restore) makes the `register_shutdown_function` backstop write the pending batch under the *new* cwd (e.g. `/tmp/.warp/timings`); `warp shard`/`warp merge` read the project dir, find nothing, and silently degrade. This is a direct extension of REQ-089 (`.do-work/archive/REQ-089-timings-cwd-fallback.md`) whose fix was narrower than the bug class — cite it and do not re-shrink the fix.

## Acceptance Criteria

- [ ] A relative `WARP_TIMINGS_DIR` value is converted to an absolute path at `fromEnv()` time (prefixed with `getcwd()`), before being stored
- [ ] An absolute `WARP_TIMINGS_DIR` value is stored unchanged
- [ ] A store constructed with a relative env dir, followed by `chdir()` to another directory, still writes pending batches under the original directory (regression test simulating the shutdown-after-chdir scenario)
- [ ] The REQ-089 deleted-cwd fallback behavior is preserved (its existing test stays green)

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce the original bug first: construct via `fromEnv()` with a relative `WARP_TIMINGS_DIR`, `chdir()` elsewhere, call `writePending()`, assert the batch landed under the original cwd's dir (must fail pre-fix)
   - Expected: batch file exists under the original directory, nothing written under the new cwd
2. **test** `./vendor/bin/pest --filter=TimingStoreTest`
   - Expected: all pass, including the REQ-089 fallback tests
