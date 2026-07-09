# REQ-064: Guard flock() failure in FileLock


**UR:** UR-012
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed commit:2449d07
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Support/FileLock.php, tests/Unit/Support/FileLockTest.php
**Depends on:**

## Task

`FileLock::withLock()` calls `flock($handle, LOCK_EX);` and discards the return value, then runs the callback unconditionally. If `flock` returns `false` (an error acquiring the lock, distinct from blocking contention — `LOCK_EX` without `LOCK_NB` blocks rather than returning false), the critical section runs with no mutual exclusion. Check the `flock` return; on `false`, throw a `RuntimeException` (consistent with the existing `cannot open file lock` error path) rather than proceeding, and ensure the handle is still closed.

## Context

Code-review finding #6 (CONFIRMED). `FileLock` was introduced by UR-011/REQ-047 as the shared lock helper extracted from `SnapshotStore`. It guards `TimingStore::mergeToDisk()` and `SnapshotStore` golden-build promotion — both read-modify-write critical sections. On filesystems where advisory `flock` is unsupported or fails (some NFS/overlay/container mounts), silently proceeding lets two concurrent merges/promotions run at once, the exact race the lock exists to prevent. Low reachability on local dev/CI disk, but a 2-line guard closes it. Keep the existing `try/finally` unlock+close intact.

## Acceptance Criteria

- [x] When `flock($handle, LOCK_EX)` returns `false`, `withLock()` throws a `RuntimeException` and does NOT invoke the callback.
- [x] On the failure path the open file handle is still closed (no leaked descriptor) — verify the `finally`/cleanup still runs or the handle is closed before throwing.
- [x] The existing success path (lock acquired → callback runs → unlock + close) is unchanged; all current `FileLockTest` cases still pass.
- [x] A new test asserts that a simulated `flock` failure results in a thrown `RuntimeException` with the callback never called (e.g. via a callback that flips a flag / increments a spy).

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter=FileLock` — Expected: all FileLock tests pass, including the new flock-failure test asserting the callback is skipped and a `RuntimeException` is thrown.
2. **test** `./vendor/bin/pest --filter="TimingStore|SnapshotStore"` — Expected: consumers of `FileLock` (merge-to-disk, snapshot promotion) still pass green, confirming no regression to the success path.

## Outputs

- src/Support/FileLock.php — Checks flock acquisition result and throws RuntimeException after closing the handle on failure.
- tests/Unit/Support/FileLockTest.php — Adds simulated flock-failure coverage asserting callback is skipped and handle cleanup occurs.
