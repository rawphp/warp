# REQ-091: FileLock reports open failure reason

**UR:** UR-015
**Status:** backlog
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Support/FileLock.php, tests/Unit/Support/FileLockTest.php
**Depends on:**

## Task

Preserve the OS/PHP warning detail when `FileLock` cannot open a lock file.

## Context

Confirmed finding 11: `FileLock::withLock()` uses `@fopen()` and throws a generic RuntimeException when the lock file cannot be opened, losing the warning reason such as "Permission denied" or "No such file or directory." The finding was confirmed even though it was lower severity than the top-10 report.

## Acceptance Criteria

- [ ] When `fopen()` fails, `FileLock::withLock()` throws a `[warp]`-prefixed RuntimeException that includes the lock path and the underlying warning reason from `error_get_last()` when available.
- [ ] The callback is not invoked when opening the lock file fails.
- [ ] Existing flock-failure behavior is unchanged: lock acquisition failures still close the handle and throw the acquire-lock exception.
- [ ] Tests cover both an open failure with available warning detail and the existing flock-failure path.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="FileLock"`
   - Expected: FileLock tests pass, including an open-failure assertion that contains the OS/PHP warning reason.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green; lock behavior in timing merge and DB snapshot helpers remains compatible.
