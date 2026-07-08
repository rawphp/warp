# REQ-044: S3 shard-spread bench harness

**UR:** UR-010
**Status:** backlog
**Created:** 2026-07-09
**Layer:** bench
**Entry point:** `bench/shard-spread.sh /path/to/app <shards> [suite-path]`
**Terminal state:** The bench records timings, compares count-based shard spread to Warp LPT spread, prints the report, and cleans up local `.warp` timing artifacts after verification.
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** bench/shard-spread.php, bench/shard-spread.sh
**Depends on:** REQ-037, REQ-040, REQ-041, REQ-042

## Task

Implement plan **Task 9**: add executable `bench/shard-spread.php` and `bench/shard-spread.sh` to record real timings, compare count-based alphabetical chunks against duration-balanced LPT bins, and print spread/max-mean statistics.

## Context

This is the S3 gate harness. It is bench tooling rather than library API, matching the existing `bench/` pattern, but it verifies the full timing artifact and sharding algorithm work together against a real suite.

## Acceptance Criteria

- [ ] `bench/shard-spread.php` accepts `<timings-dir> <shards> [paths...]`, rejects missing/invalid args, and exits 2 when no timings exist.
- [ ] The report includes file count, total recorded milliseconds, per-shard count-based and Warp LPT predicted durations, spread, and max/mean ratios.
- [ ] `bench/shard-spread.sh` records timings with `WARP_TIMINGS=1 WARP_TIMINGS_DIR=.warp/timings ./vendor/bin/pest --parallel "$SUITE"` before running the report.
- [ ] Both bench scripts are executable.
- [ ] Running the harness against this repo's unit suite produces a table and `php bin/warp timings --timings-dir=.warp/timings` prints stats without errors.

## Verification Steps

1. **runtime** `bench/shard-spread.sh . 4 tests/Unit`
   - Expected: recording run passes and the report prints four shards with Warp LPT spread stats.
2. **runtime** `php bin/warp timings --timings-dir=.warp/timings`
   - Expected: prints timing artifact stats without errors.
3. **runtime** `rm -rf .warp`
   - Expected: local timing artifact is removed after the bench verification.
4. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.

## Integration

**Reachability:** Developers invoke `bench/shard-spread.sh` directly from the repo or from documentation added in REQ-045.

**Data dependencies:** Reads and writes `.warp/timings` in the target app and discovers suite files from the supplied paths.

**Service dependencies:** Consumes `TimingStore` from REQ-037, the registered timing extension from REQ-040, `TestFileFinder` from REQ-041, and `DurationBalancedSharder` from REQ-042.
