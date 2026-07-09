# REQ-059: Collapse WarpMode's triplicated env-flag idiom into one helper

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.21409
**Claimed at:** 2026-07-09T02:00:41Z
**Heartbeat:** 2026-07-09T02:00:41Z
<!-- claimed-end -->

**UR:** UR-011
**Status:** in-progress
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** S
**Files:** src/WarpMode.php, tests/Unit/WarpModeTest.php
**Depends on:**

## Task

Pure refactor (over-cap finding C2): `WarpMode::enabled()`, `databaseEnabled()`, and `timingsEnabled()` (src/WarpMode.php:11, 16, 21) are three verbatim copies of `in_array(getenv(X), ['1', 'on', 'true'], true)` differing only in the env var name. Extract a private `static function flag(string $var): bool` holding the accepted-values list once; the three public methods become one-line delegations. No behaviour change — the existing tests pin the accepted/rejected value sets (including case-sensitive rejection of 'TRUE'/'yes').

## Context

The accepted truthy set currently lives in three places; the next flag makes four, and widening the set later means three edits and a likely missed one. Behaviour is pinned by tests/Unit/WarpModeTest.php.

## Acceptance Criteria

- [ ] The `in_array(getenv(...), [...], true)` idiom appears exactly once in src/WarpMode.php.
- [ ] All existing WarpMode tests pass unchanged (no behaviour change, including rejection of 'TRUE', 'yes', '0', empty).

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/WarpModeTest.php`
   - Expected: all existing tests pass without modification.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green.
