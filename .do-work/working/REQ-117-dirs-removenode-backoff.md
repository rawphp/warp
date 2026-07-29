# REQ-117: Collapse Dirs delete into removeNode + backoff

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.98262
**Claimed at:** 2026-07-29T05:04:38Z
**Heartbeat:** 2026-07-29T05:04:38Z
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
**Priority:** 3
**Size:** M
**Files:** src/Db/Dirs.php, tests/Unit/Db/DirsTest.php
**Depends on:**

## Task

Restructure `RawPHP\Warp\Db\Dirs` so recursive delete uses one shared `removeNode` (or equivalent) pipeline for unlink/rmdir warning capture and ENOENT handling, plus a bounded not-empty retry loop with a documented short backoff (~10–20ms) between attempts. Drop public static `$beforeFsOp`. Keep race unit tests via a package-internal / `@internal` test-only collaborator (not public package surface). Preserve the UR-020 failure model: mid-walk ENOENT = success; not-empty retries then `[warp]` RuntimeException; non-ENOENT failures still throw `[warp]`.

## Context

Code-review follow-up to UR-020 (REQ-114–116). Review findings: public `$beforeFsOp` is library API surface pollution; `unlinkPath`/`tryRmdir` are duplicated; not-empty retries spin with zero backoff. Clarifications: behavior-preserving UR-020 model; package-internal seam for the same race cases; fixed ~10–20ms backoff constant (e.g. `NOT_EMPTY_BACKOFF_US`). Connector: optional reuse of the `FileLock` set_error_handler capture pattern is fine but not required if a local single helper is clearer.

## Acceptance Criteria

- [ ] `Dirs` has a single shared path for unlink/rmdir warning capture + ENOENT classification (no duplicated unlinkPath/tryRmdir pipelines)
- [ ] Public static `$beforeFsOp` is gone from the package surface (no `public static ?Closure $beforeFsOp`)
- [ ] Race injection for unit tests uses a package-internal / `@internal` test-only collaborator only
- [ ] Not-empty retries still bounded (existing max attempts constant or equivalent); each failed not-empty attempt (except possibly the last) sleeps ~10–20ms via a named constant
- [ ] Mid-walk unlink ENOENT succeeds without ErrorException under a warning→ErrorException handler
- [ ] Temporary not-empty on first rmdir then empty on retry succeeds
- [ ] Non-ENOENT unlink failures still throw `RuntimeException` with `[warp]` prefix
- [ ] Exhausted not-empty retries throw `RuntimeException` with `[warp]` prefix
- [ ] Quiet nested-tree / plain-file / missing-path delete behavior still works

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Db/DirsTest.php`
   - Expected: all Dirs unit tests PASS, including race-simulation cases rewritten for the internal seam; no PHP warnings
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS; no regressions from the Dirs restructure

## Manual checks (advisory)

- [ ] Optional: parallel host app with WARP_MODE/WARP_DB still green on recycle/shutdown cleanup — Observable outcome: no unlink/rmdir ErrorExceptions attributed to Dirs.php
