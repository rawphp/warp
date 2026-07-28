# REQ-115: Settle after mysqld stop before datadir delete

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.28409
**Claimed at:** 2026-07-28T12:18:40Z
**Heartbeat:** 2026-07-28T12:18:40Z
<!-- claimed-end -->


**UR:** UR-020
**Status:** in-progress
**Created:** 2026-07-28
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Db/SnapshotDatabaseManager.php, src/Db/MysqldServer.php, tests/Integration/Db/SnapshotDatabaseManagerTest.php
**Depends on:** REQ-114

## Task

After `MysqldServer::stop()` returns in `SnapshotDatabaseManager::recycle()` and `shutdown()`, ensure the process is fully gone and apply a short settle (or datadir-quiet check) before calling `Dirs::delete` on the worker datadir / worker dir. Review `sweepDeadWorkers()` so it never deletes a live worker’s tree mid-write (confirm pid + mtime guards; tighten only if a concrete gap exists). Prefer minimal latency impact — settle must be short and only on teardown paths.

## Context

Issue #271: even after SIGTERM and process exit, InnoDB may still churn `#ib_redo*_tmp` under the datadir. REQ-114 makes delete resilient; this REQ reduces how often the race fires by sequencing stop → quiet → delete.

`MysqldServer::stop()` already waits until `proc_get_status` reports not running (then closes). That does not prove the datadir is quiet. `sweepDeadWorkers` skips dirs younger than 60s and live owner/mysqld pids — double-check that path still cannot reap a live sibling under parallel load.

Depends on REQ-114 so teardown always uses race-safe delete.

## Acceptance Criteria

- [ ] `recycle()` and `shutdown()` only call `Dirs::delete` after `stop()` has completed and a documented short settle / quiet check has run (implementation choice: fixed µs sleep vs. poll for process/pid-file absence — pick the smallest change that is justified)
- [ ] Settle does not change golden snapshot build or clone semantics — only post-stop teardown sequencing
- [ ] `sweepDeadWorkers()` still never deletes a directory whose `owner.pid` or mysqld pid is alive; if a gap is found, fix it with a test
- [ ] Existing SnapshotDatabaseManager integration tests still pass; add or extend a test only if behavior is newly assertable without flaking
- [ ] Full suite `./vendor/bin/pest` passes

## Verification Steps

1. **test** `./vendor/bin/pest --filter=SnapshotDatabaseManager`
   - Expected: PASS (existing recycle/exception-safety tests plus any new settle-related coverage)
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS

## Manual checks (advisory)

- [ ] Optional stress: from a host app with `WARP_MODE`/`WARP_DB`, run `php artisan test --parallel` (or YardPilot release dry-run) and confirm zero failures of class `unlink/rmdir … /tmp/warp-db … Dirs.php` — Observable outcome: suite green without cleanup-class ErrorExceptions

## Outputs

- src/Db/SnapshotDatabaseManager.php — post-stop settle before delete; sweep review
- src/Db/MysqldServer.php — only if stop/ready helpers are extended
- tests/Integration/Db/SnapshotDatabaseManagerTest.php — if new assertable behaviour is added
