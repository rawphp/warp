# REQ-036: `WarpMode::timingsEnabled()` — WARP_TIMINGS env switch

**UR:** UR-010
**Status:** done
**Created:** 2026-07-09
**Layer:** package
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** `WarpMode::timingsEnabled()` implemented with strict truthy parsing and verified by focused and full package test suites.
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/WarpMode.php, tests/Unit/WarpModeTest.php
**Depends on:**

## Task

Implement plan **Task 1** of `docs/superpowers/plans/2026-07-08-s3-timing-capture-sharding.md`: add `WarpMode::timingsEnabled(): bool` so `WARP_TIMINGS` is enabled only for `1`, `on`, or `true`. Follow the plan's TDD steps and code exactly unless verified broken.

## Context

UR-010 executes the S3 timing capture and duration-balanced sharding plan. The plan document is the authoritative implementation source; this REQ provides coordination and verification. Existing env switches in `src/WarpMode.php` use strict `in_array(getenv(...), ['1', 'on', 'true'], true)` parsing.

## Acceptance Criteria

- [x] `WarpMode::timingsEnabled()` returns true for `WARP_TIMINGS=1`, `on`, and `true`.
- [x] `timingsEnabled()` returns false for unset, `0`, `off`, `false`, `yes`, `TRUE`, and empty string.
- [x] `timingsEnabled()` is independent of `WARP_MODE` and `WARP_DB`.
- [x] `tests/Unit/WarpModeTest.php` clears `WARP_TIMINGS` in `afterEach`.

## Verification Steps

1. **test** `./vendor/bin/pest tests/Unit/WarpModeTest.php`
   - Expected: PASS including the new WARP_TIMINGS cases.
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.

## Integration

**Reachability:** Called by the timing extension that REQ-040 registers through `phpunit.xml`; host test invocations enable it with `WARP_TIMINGS=1 ./vendor/bin/pest`.

**Data dependencies:** Reads only the `WARP_TIMINGS` environment variable.

**Service dependencies:** Extends existing `RawPHP\Warp\WarpMode` in `src/WarpMode.php`, mirroring `enabled()` and `databaseEnabled()`.

## Outputs

- `src/WarpMode.php` — added `WarpMode::timingsEnabled()` using the same strict env parsing as the existing mode switches.
- `tests/Unit/WarpModeTest.php` — added WARP_TIMINGS reset and truthy/falsey/independence coverage.

## Verification Evidence

- `./vendor/bin/pest tests/Unit/WarpModeTest.php` — PASS, 35 tests / 35 assertions.
- `./vendor/bin/pest` — PASS, 147 tests / 255 assertions after replacing the worktree-only `vendor` symlink with a local install because symlinked Pest resolved test paths through the main checkout.
- `./vendor/bin/pint --dirty` — PASS.
