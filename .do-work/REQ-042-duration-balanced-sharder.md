# REQ-042: Deterministic duration-balanced LPT sharder

**UR:** UR-010
**Status:** backlog
**Created:** 2026-07-09
**Layer:** package
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

Implement plan **Task 7**: create `RawPHP\Warp\Shard\DurationBalancedSharder::assign()` using deterministic longest-processing-time bin packing with average fallback weights for unmeasured files and strict shard spec validation.

## Context

This is the core CI sharding algorithm. Every CI shard machine computes the entire assignment independently, so ordering, tie-breaks, and invalid input behavior must be stable.

## Acceptance Criteria

- [ ] Dominant files are isolated while smaller files fill the remaining shard capacity.
- [ ] Shards are disjoint and cover every input file across all shard indexes.
- [ ] Unmeasured files use the average known timing, or `1.0` when no timings exist.
- [ ] With no timings, assignment degrades to deterministic count-balanced round-robin.
- [ ] Out-of-range shard specs throw `InvalidArgumentException` with `[warp] shard index out of range`.

## Verification Steps

1. **test** `./vendor/bin/pest tests/Unit/Shard/DurationBalancedSharderTest.php`
   - Expected: PASS for all plan Task 7 sharder tests.
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.

## Integration

**Reachability:** Called by `ShardCommand` in REQ-043 and `bench/shard-spread.php` in REQ-044.

**Data dependencies:** Consumes discovered test file lists and per-file timing totals from `TimingStore`.

**Service dependencies:** Pure PHP algorithm under the existing Composer PSR-4 namespace `RawPHP\Warp\`.
