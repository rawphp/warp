# REQ-114: Make Dirs::delete race-safe under concurrent FS churn

**UR:** UR-020
**Status:** backlog
**Created:** 2026-07-28
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 3
**Size:** M
**Files:** src/Db/Dirs.php, tests/Unit/Db/DirsTest.php
**Depends on:**

## Task

Harden `RawPHP\Warp\Db\Dirs::delete()` so recursive teardown is idempotent under concurrent filesystem churn (InnoDB `#innodb_redo/*_tmp` disappearing mid-walk, new files appearing before `rmdir`). Ignore ENOENT on unlink/rmdir; retry "directory not empty" a bounded number of times; do not convert cleanup races into PHP warnings/`ErrorException` that fail green tests. Do **not** swallow non-ENOENT failures (permission denied, unexpected errors) — those must still surface.

## Context

YardPilot issue [#271](https://github.com/original-solutions/yardpilot/issues/271): full parallel Pest with `WARP_MODE` + `WARP_DB` intermittently fails with ~10 `ErrorException`s attributed to innocent bystander tests. Stack points at `vendor/rawphp/warp/src/Db/Dirs.php` bare `unlink`/`rmdir` during `recycle()` / `shutdown()`.

Current `Dirs::delete` (REQ-021) is a silent no-op only when the **root** path is missing. Mid-walk TOCTOU still calls bare `unlink`/`rmdir`, which under Laravel’s testing error handler become hard failures.

Connector: all WARP_DB callers share this util (`SnapshotDatabaseManager`, `CopyOnWriteCloner`, `SnapshotStore`, `GoldenSnapshotBuilder`). Fixing the util covers every path.

## Acceptance Criteria

- [ ] `Dirs::delete($path)` treats mid-walk `unlink`/`rmdir` ENOENT as success (file already gone) and continues
- [ ] When `rmdir` fails with "Directory not empty", `delete` retries child cleanup + rmdir a bounded number of times (document the bound in code), then either succeeds or throws a clear `RuntimeException` with `[warp]` prefix if still non-empty for non-transient reasons
- [ ] Successful delete leaves no residual path for a quiet tree (existing nested-tree / plain-file / missing-path tests still pass)
- [ ] Unit tests simulate race conditions: (1) file vanishes between readdir and unlink, (2) directory temporarily non-empty on first rmdir then empty on retry — both complete without warning/`ErrorException`
- [ ] Non-ENOENT failures are not silenced (e.g. still throw or propagate when the operation cannot succeed)
- [ ] `./vendor/bin/pest tests/Unit/Db/DirsTest.php` and full `./vendor/bin/pest` pass

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Db/DirsTest.php`
   - Expected: PASS including new race-simulation tests; no PHP warnings
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS, no regressions

## Outputs

- src/Db/Dirs.php — resilient recursive delete
- tests/Unit/Db/DirsTest.php — race / ENOENT / not-empty-retry coverage
