# REQ-057: Bench consumes sharder plan API; guard zero discovered files

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.21409
**Claimed at:** 2026-07-09T02:00:41Z
**Heartbeat:** 2026-07-09T02:00:41Z
<!-- claimed-end -->

**UR:** UR-011
**Status:** in-progress
**Created:** 2026-07-09
**Layer:** bench
**Entry point:**
**Terminal state:**
**Parent:** REQ-054
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** S
**Files:** bench/shard-spread.php, bench/shard-spread.sh
**Depends on:** REQ-056

## Task

1. Rewrite `bench/shard-spread.php` to consume `DurationBalancedSharder::plan()` (REQ-056): one plan call yields all bins and loads; delete the duplicated fallback-weight closure (lines 32-34) and the per-shard `assign()` loop (lines 46-48).
2. Guard the empty-discovery case (over-cap bench finding): when zero test files are discovered, print a usage/diagnostic message and exit 1 instead of letting `array_chunk($files, 0)` throw an uncaught `ValueError` (line 37).

## Context

Over-cap findings: the bench re-implements the sharder's private fallback policy, so the S3 gate report can silently measure a different policy than the one shipped; and `(int) ceil(0/N) === 0` makes `array_chunk` fatal on an empty file set. The bench is the evidence artifact for shard-spread quality (REQ-044 heritage) — it must measure the real sharder.

## Acceptance Criteria

- [ ] `bench/shard-spread.php` contains no local weight/fallback computation — weights and loads come from the sharder's public API.
- [ ] Running the bench against a paths argument containing no `*Test.php` files prints a clear message and exits 1 (no ValueError, no stack trace).
- [ ] Bench output for a fixture timings dir is consistent with `warp shard` plans for the same inputs (same bin membership).

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **runtime** `php bench/shard-spread.php <fixture-timings-dir> 4 tests`
   - Expected: spread report prints; per-bin loads sum to the total of recorded durations; bin membership matches `warp shard k/4 tests` for each k.
2. **runtime** `php bench/shard-spread.php <fixture-timings-dir> 4 src; echo $?`
   - Expected: diagnostic message about zero discovered test files; exit code 1.
3. **test** `./vendor/bin/pest`
   - Expected: full suite green (bench has no unit tests; suite guards the sharder API it consumes).

## Integration

**Reachability:** Invoked via `bench/shard-spread.sh` and directly (`php bench/shard-spread.php <timings-dir> <shards> <paths...>`) — the S3 gate evidence harness (REQ-044).

**Data dependencies:** Reads a timings dir via `TimingStore` and discovers files via `TestFileFinder` (src/Shard/TestFileFinder.php).

**Service dependencies:** `DurationBalancedSharder::plan()` from REQ-056 (hard dependency).
