# REQ-089: TimingStore fromEnv handles missing cwd

**UR:** UR-015
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Closure proof:** checkpoint_log:passed all 2 verification checkpoints passed; commit:98c1804
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php, tests/Integration/Timing/TimingCaptureTest.php
**Depends on:**

## Task

Fix `TimingStore::fromEnv()` so an unavailable current working directory does not resolve the default timings directory to `/.warp/timings`.

## Context

Confirmed finding 9: `fromEnv()` concatenates `getcwd().'/.warp/timings'` without guarding `getcwd() === false`, unlike other call sites that use `getcwd() ?: '.'`. If the process cwd is deleted or unreadable and `WARP_TIMINGS_DIR` is unset, timing capture can try to write at the filesystem root.

## Acceptance Criteria

- [x] When `WARP_TIMINGS_DIR` is unset and `getcwd()` is unavailable, `TimingStore::fromEnv()` falls back to `./.warp/timings` or an equivalent non-root relative path, never `/.warp/timings`.
- [x] A child-process regression test reproduces the unavailable-cwd case without requiring root privileges.
- [x] When `WARP_TIMINGS_DIR` is set to a non-empty value, `fromEnv()` still uses it exactly as before.
- [x] Existing shard command handling of `getcwd() ?: '.'` remains compatible with the timing-store fallback.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="TimingStore|TimingCapture"`
   - Expected: timing-store tests pass, including a child-process missing-cwd regression that does not attempt to create `/.warp/timings`.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green; explicit `WARP_TIMINGS_DIR` behavior remains unchanged.

## Outputs

- src/Timing/TimingStore.php — Uses `getcwd() ?: '.'` when no explicit `WARP_TIMINGS_DIR` is configured.
- tests/Unit/Timing/TimingStoreTest.php — Adds a child-process unavailable-cwd regression and preserves explicit `WARP_TIMINGS_DIR` coverage.
