# REQ-066: Guard all-zero-weight collapse in DurationBalancedSharder

**UR:** UR-012
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
**Files:** src/Shard/DurationBalancedSharder.php, tests/Unit/Shard/DurationBalancedSharderTest.php
**Depends on:**

## Task

In `DurationBalancedSharder::plan()` the LPT loop assigns each file to `array_search(min($loads), $loads, true)`. When every file's weight is exactly `0.0`, `$loads` stays all-`0.0`, `min()` is `0.0`, and strict `array_search(0.0, $loads, true)` always returns index `0` — so every file piles into shard 0 and the other shards come back empty (`warp shard 2/N` etc. exit with "more shards than test files"). Make the assignment resilient to a degenerate all-equal-weight input: when all weights are equal (including all-zero), fall back to count-balanced round-robin distribution so files spread across shards instead of collapsing into shard 0.

## Context

Code-review finding #7 (PLAUSIBLE). The trigger — all weights exactly `0.0` — is hard to reach through the normal collector (`TimingCollector` rounds real durations above `0.0`, and the no-timings path already falls back to a `1.0` weight), but it is reachable with malformed/all-zero timing data in the store, and the collapse silently defeats sharding exactly when it should degrade gracefully. This is a defensive guard on the algorithm; do not change the behaviour for normal (non-degenerate) weight distributions.

## Acceptance Criteria

- [ ] Given N files all with weight `0.0` and M shards (M ≤ N), `plan()` distributes files across all M shards (no shard empty when N ≥ M) instead of piling all into shard 0.
- [ ] The all-equal-nonzero-weight case (e.g. every file weight `1.0`) also distributes evenly rather than collapsing.
- [ ] Normal mixed-weight LPT behaviour is unchanged — existing `DurationBalancedSharderTest` cases for realistic weight distributions still pass with identical assignments.
- [ ] Tie-breaking remains deterministic (same input → same assignment across runs).

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter=DurationBalancedSharder` — Expected: all pass, including a new case asserting that all-zero weights across N files and M shards yield M non-empty, evenly-sized shards (not a single shard 0), and that mixed-weight assignments are byte-for-byte unchanged.
