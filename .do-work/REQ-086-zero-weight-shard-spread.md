# REQ-086: Zero-weight files spread deterministically

**UR:** UR-015
**Status:** backlog
**Created:** 2026-07-09
**Layer:** none
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Shard/DurationBalancedSharder.php, tests/Unit/Shard/DurationBalancedSharderTest.php
**Depends on:**

## Task

Fix duration-balanced sharding so files with recorded `0.0` millisecond weights do not all cluster onto one shard.

## Context

Confirmed finding 6: LPT assignment only increases bin load by the file's weight, so zero-weight files keep selecting the same lightest bin. Clarification from ideate: the fix must preserve deterministic shard agreement across CI nodes; do not use nondeterministic tie-breaking or process-local randomness.

## Acceptance Criteria

- [ ] When every discovered file has a recorded `0.0` total, `DurationBalancedSharder::plan()` distributes files deterministically across available shards instead of placing all files into shard 1.
- [ ] Mixed positive and zero weights still produce deterministic, disjoint shards that cover every file exactly once.
- [ ] `assign()` no longer returns empty shards when there are at least as many files as shards solely because all recorded weights are zero.
- [ ] Existing no-timings count-balanced fallback behavior remains unchanged.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="DurationBalancedSharder"`
   - Expected: sharder tests pass, including all-zero and mixed-zero timing regression cases with deterministic expected plans.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green; shard planning remains deterministic across repeated calls.
