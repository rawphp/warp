# REQ-118: Drop settleAfterStop; keep sweep live-pid guard

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.98262
**Claimed at:** 2026-07-29T05:12:03Z
**Heartbeat:** 2026-07-29T05:12:03Z
<!-- claimed-end -->


**UR:** UR-021
**Status:** in-progress
**Created:** 2026-07-29
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** S
**Files:** src/Db/SnapshotDatabaseManager.php, tests/Unit/Db/SnapshotDatabaseManagerTeardownTest.php
**Depends on:** REQ-117

## Task

Remove `SnapshotDatabaseManager::settleAfterStop()` and all call sites in `recycle()` / `shutdown()`. Do **not** fold a usleep into `MysqldServer::stop()`. Rely on REQ-117’s resilient `Dirs::delete` (removeNode + not-empty backoff) for residual InnoDB churn after stop. Keep the `sweepDeadWorkers` alive-after-TERM guard (second `alive()` check after TERM + sleep) and keep its existing unit tests. Remove only the settle duration test (reflection into `settleAfterStop`); leave live-owner / live-mysqld / dead-reap tests unchanged in intent.

## Context

Code-review follow-up to UR-020 REQ-115. Clarification: delete settle rather than fold into `stop()` — “fewer moving pieces”; process lifecycle stays in `MysqldServer::stop()`, FS resilience stays in `Dirs`. Depends on REQ-117 so settle is only removed after delete is self-healing with backoff. Golden build stop→delete paths already relied on race-safe delete without settle.

## Acceptance Criteria

- [ ] `settleAfterStop` method no longer exists on `SnapshotDatabaseManager`
- [ ] `recycle()` and `shutdown()` call `server->stop()` then `Dirs::delete` without an intermediate settle helper or bare usleep introduced for settle
- [ ] `MysqldServer::stop()` is not modified to add settle sleep for this REQ
- [ ] `sweepDeadWorkers` still skips delete when mysqld pid is alive after TERM + wait (second `alive()` check preserved)
- [ ] Unit tests still prove: live owner not reaped; live mysqld after TERM not reaped; stale dead worker reaped
- [ ] No test asserts a fixed settle duration / reflects into `settleAfterStop`

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter=SnapshotDatabaseManager`
   - Expected: manager + teardown unit/integration coverage PASS; no settle-duration test remains
2. **test** `./vendor/bin/pest tests/Unit/Db/DirsTest.php`
   - Expected: still PASS (delete self-healing remains the post-stop defense)
3. **test** `./vendor/bin/pest`
   - Expected: full suite PASS

## Manual checks (advisory)

- [ ] Optional parallel Pest host app with WARP_DB — Observable outcome: recycle/shutdown cleanup stays free of Dirs unlink/rmdir ErrorExceptions without settleAfterStop
