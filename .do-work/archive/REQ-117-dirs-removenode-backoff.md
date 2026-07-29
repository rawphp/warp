# REQ-117: Collapse Dirs delete into removeNode + backoff


**UR:** UR-021
**Status:** done
**Created:** 2026-07-29
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed commit:5e2d9d6
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

- [x] `Dirs` has a single shared path for unlink/rmdir warning capture + ENOENT classification (no duplicated unlinkPath/tryRmdir pipelines)
- [x] Public static `$beforeFsOp` is gone from the package surface (no `public static ?Closure $beforeFsOp`)
- [x] Race injection for unit tests uses a package-internal / `@internal` test-only collaborator only
- [x] Not-empty retries still bounded (existing max attempts constant or equivalent); each failed not-empty attempt (except possibly the last) sleeps ~10–20ms via a named constant
- [x] Mid-walk unlink ENOENT succeeds without ErrorException under a warning→ErrorException handler
- [x] Temporary not-empty on first rmdir then empty on retry succeeds
- [x] Non-ENOENT unlink failures still throw `RuntimeException` with `[warp]` prefix
- [x] Exhausted not-empty retries throw `RuntimeException` with `[warp]` prefix
- [x] Quiet nested-tree / plain-file / missing-path delete behavior still works

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Db/DirsTest.php`
   - Expected: all Dirs unit tests PASS, including race-simulation cases rewritten for the internal seam; no PHP warnings
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS; no regressions from the Dirs restructure

## Manual checks (advisory)

- [x] Optional: parallel host app with WARP_MODE/WARP_DB still green on recycle/shutdown cleanup — Observable outcome: no unlink/rmdir ErrorExceptions attributed to Dirs.php


## Outputs

- src/Db/Dirs.php — removeNode pipeline, NOT_EMPTY_BACKOFF_US, @internal installTestBeforeFsOp; public $beforeFsOp removed
- tests/Unit/Db/DirsTest.php — Race tests rewritten for internal seam; surface/backoff/pipeline structure assertions
