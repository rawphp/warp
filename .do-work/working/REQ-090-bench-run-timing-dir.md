# REQ-090: Bench shard spread uses fresh timing dirs

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.82488
**Claimed at:** 2026-07-09T20:37:07Z
**Heartbeat:** 2026-07-09T20:37:07Z
<!-- claimed-end -->
**UR:** UR-015
**Status:** in-progress
**Created:** 2026-07-09
**Layer:** none
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** bench/shard-spread.sh, tests/Integration/Cli/WarpBinTest.php
**Depends on:**

## Task

Fix `bench/shard-spread.sh` so stale `.warp/timings` artifacts from previous runs cannot satisfy the Pest-failure guard.

## Context

Confirmed finding 10: the script continues after Pest failure if any `*.json` exists under `.warp/timings`, including stale artifacts from prior bench invocations. Clarification: use a fresh run-specific timing directory per invocation, and only continue on Pest failure if that run produced artifacts. Do not delete an application's existing `.warp/timings` history.

## Acceptance Criteria

- [ ] Each `bench/shard-spread.sh` invocation writes `WARP_TIMINGS_DIR` to a fresh run-specific directory.
- [ ] If Pest exits non-zero and the fresh run directory contains no timing artifact, the script exits with Pest's non-zero status even when stale `.warp/timings/*.json` files exist elsewhere in the app.
- [ ] If Pest exits non-zero after producing timing artifacts in the fresh run directory, the script preserves the existing "continue with warning" behavior.
- [ ] The script does not delete or overwrite pre-existing `.warp/timings` artifacts in the target app.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="WarpBin|shard spread|bench"`
   - Expected: integration tests pass, including a shell-level bench regression where stale artifacts no longer mask a crashed Pest run.
2. **runtime** `bash bench/shard-spread.sh "$(pwd)" 2 tests/Unit/WarpModeTest.php`
   - Expected: the script records fresh timings for this invocation and runs `bench/shard-spread.php` against that fresh timing directory.
3. **test** `./vendor/bin/pest`
   - Expected: full suite green; existing bench and shard CLI behavior remains compatible.
