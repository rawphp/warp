# REQ-042: Deterministic duration-balanced LPT sharder

**UR:** UR-010
**Status:** done
**Created:** 2026-07-09
**Layer:** package
**Entry point:** `RawPHP\Warp\Shard\DurationBalancedSharder::assign()`
**Terminal state:** merged
**Parent:**
**Closure proof:** Merged as `merge(REQ-042): duration-balanced sharder`; focused sharder tests passed, full Pest suite passed, and Pint passed.
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

- [x] Dominant files are isolated while smaller files fill the remaining shard capacity.
- [x] Shards are disjoint and cover every input file across all shard indexes.
- [x] Unmeasured files use the average known timing, or `1.0` when no timings exist.
- [x] With no timings, assignment degrades to deterministic count-balanced round-robin.
- [x] Out-of-range shard specs throw `InvalidArgumentException` with `[warp] shard index out of range`.

## Verification Steps

1. **test** `./vendor/bin/pest tests/Unit/Shard/DurationBalancedSharderTest.php`
   - Expected: PASS for all plan Task 7 sharder tests.
   - Actual: PASS, 10 tests / 20 assertions.
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.
   - Actual: PASS, 175 tests / 402 assertions.
3. **format** `./vendor/bin/pint --dirty`
   - Actual: PASS.

## Integration

**Reachability:** Called by `ShardCommand` in REQ-043 and `bench/shard-spread.php` in REQ-044.

**Data dependencies:** Consumes discovered test file lists and per-file timing totals from `TimingStore`.

**Service dependencies:** Pure PHP algorithm under the existing Composer PSR-4 namespace `RawPHP\Warp\`.
