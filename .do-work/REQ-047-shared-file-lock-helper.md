# REQ-047: Extract shared file-lock helper from SnapshotStore

**UR:** UR-011
**Status:** backlog
**Created:** 2026-07-09
**Layer:** package
**Entry point:**
**Terminal state:**
**Parent:** REQ-046
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Support/FileLock.php, src/Db/SnapshotStore.php, tests/Unit/Support/FileLockTest.php
**Depends on:**

## Task

Extract the fopen(`'c'`)/false-check/`flock(LOCK_EX)`/try-finally/`LOCK_UN`+`fclose` choreography currently inlined in `SnapshotStore::withLock()` (src/Db/SnapshotStore.php:30-48) into a reusable helper, e.g. `Support\FileLock::withLock(string $lockFile, \Closure $callback): mixed`, and repoint `SnapshotStore` at it. Do NOT touch `TimingStore` in this REQ — REQ-051 repoints the timing side to avoid footprint conflicts with the REQ-048→050 chain.

## Context

Review over-cap finding C3: `TimingStore::mergePending()` re-implements the identical lock choreography (including a near-identical RuntimeException message) that `SnapshotStore::withLock()` already contains. Two hand-rolled copies mean a fix to one misses the other. REQ-051/REQ-052 will consume this helper for the explicit `warp merge` write path.

## Acceptance Criteria

- [ ] `Support\FileLock::withLock()` exists, takes a lock-file path and a closure, returns the closure's return value, and always releases the lock (LOCK_UN + fclose) even when the closure throws.
- [ ] `FileLock::withLock()` throws `RuntimeException` with a `[warp]`-prefixed message when the lock file cannot be opened.
- [ ] `SnapshotStore` delegates to the helper; its inline lock choreography is deleted; existing SnapshotStore behaviour is unchanged.
- [ ] Unit tests cover: closure return value passthrough, lock release on exception, and RuntimeException on unopenable lock path.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Support/FileLockTest.php`
   - Expected: all new FileLock tests pass.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green — SnapshotStore behaviour unchanged after delegation.

## Integration

**Reachability:** Internal library helper: consumed by `SnapshotStore::withLock()` (src/Db/SnapshotStore.php) immediately, and by `TimingStore`'s explicit merge path in REQ-051.

**Data dependencies:** Lock files on disk only (`merge.lock`-style paths passed by callers); no models or persisted app data.

**Service dependencies:** Extends the existing `src/Db/SnapshotStore.php` lock behaviour; no external services.
