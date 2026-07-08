# REQ-056: Expose DurationBalancedSharder plan/weights as public API

**UR:** UR-011
**Status:** backlog
**Created:** 2026-07-09
**Layer:** package
**Entry point:**
**Terminal state:**
**Parent:** REQ-054
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Shard/DurationBalancedSharder.php, tests/Unit/Shard/DurationBalancedSharderTest.php
**Depends on:**

## Task

Refactor `DurationBalancedSharder` (src/Shard/DurationBalancedSharder.php) to expose its full computation instead of recomputing per shard index:

1. Add a public `plan(array $files, array $fileTotalsMs, int $shards): array` returning ALL bins (and per-bin loads, or a companion `weights()` accessor) in one pass.
2. `assign()` delegates: `plan(...)[$index - 1]`.
3. The resolved per-file weights (including the fallback policy: mean of known totals, 1.0 when none — currently private at lines 26-27) become part of the returned/queryable data so external consumers stop duplicating the policy.
4. Preserve exact determinism: the existing weight-desc/path-asc ordering and lowest-index tie-breaking (docblock lines 12-15) must survive unchanged — existing sharder tests pin this.

## Context

Over-cap findings A4/E-bench: `bench/shard-spread.php:32-34` duplicates the sharder's private fallback-weight policy line-for-line and calls `assign()` once per shard, recomputing the entire LPT packing k times just to recover per-bin loads the sharder already computed and threw away. If the fallback policy changes, the S3 gate benchmark silently measures a policy the shipped sharder no longer uses. REQ-057 repoints the bench at this API.

## Acceptance Criteria

- [ ] `plan()` returns all bins in one pass; `assign($files, $totals, "$k/$n")` returns exactly `plan($files, $totals, $n)[$k-1]` (asserted for several k/n).
- [ ] Per-file resolved weights (with fallback applied) and per-bin loads are obtainable from the public API without re-deriving the fallback policy.
- [ ] All existing DurationBalancedSharder tests pass unchanged (determinism contract intact).

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Shard/DurationBalancedSharderTest.php`
   - Expected: existing determinism tests plus new plan()/assign() equivalence tests pass.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green.

## Integration

**Reachability:** Called by `ShardCommand::run()` (src/Cli/ShardCommand.php) today; `bench/shard-spread.php` becomes the second consumer in REQ-057.

**Data dependencies:** File-totals map from `TimingStore::fileTotals()` (src/Timing/TimingStore.php) — shape unchanged.

**Service dependencies:** None; pure in-memory computation. Existing callers' `assign()` signature is preserved.
