# REQ-038: In-process timing collector

**UR:** UR-010
**Status:** done
**Created:** 2026-07-09
**Layer:** package
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** `TimingCollector` records and flushes per-process timing batches once; focused and full package suites pass.
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Timing/TimingCollector.php, tests/Unit/Timing/TimingCollectorTest.php
**Depends on:** REQ-037

## Task

Implement plan **Task 3**: create `RawPHP\Warp\Timing\TimingCollector` to record started/finished test pairs, compute millisecond durations rounded to three decimals, expose recorded tests through `all()`, and flush exactly once to `TimingStore`.

## Context

The collector is the in-memory bridge between PHPUnit events and `TimingStore`. It must tolerate interleaved tests, missing starts, missing file attribution, and duplicate flush calls because the extension flushes both on execution finish and through a shutdown backstop.

## Acceptance Criteria

- [x] Started/finished pairs record the elapsed milliseconds with the correct file path.
- [x] Interleaved tests are tracked independently.
- [x] Finishes without starts and finishes without file attribution are ignored.
- [x] `flush()` writes one pending batch and is idempotent.
- [x] Empty flushes write nothing.

## Verification Steps

1. **test** `./vendor/bin/pest tests/Unit/Timing/TimingCollectorTest.php`
   - Expected: PASS for all plan Task 3 collector tests.
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.

## Integration

**Reachability:** Instantiated by `TimingExtension` in REQ-040 when `WARP_TIMINGS=1` is enabled.

**Data dependencies:** Holds per-process test id, file, and duration state in memory until flush.

**Service dependencies:** Consumes `RawPHP\Warp\Timing\TimingStore` from REQ-037.

## Outputs

- `src/Timing/TimingCollector.php` — in-process started/finished collector with rounded millisecond duration recording and idempotent flush.
- `tests/Unit/Timing/TimingCollectorTest.php` — coverage for deltas, interleaving, missing starts, missing files, single flush, and empty flush behavior.

## Verification Evidence

- `./vendor/bin/pest tests/Unit/Timing/TimingCollectorTest.php` — PASS, 6 tests / 6 assertions.
- `./vendor/bin/pest` — PASS, 153 tests / 261 assertions after replacing the worktree-only `vendor` symlink with a local install because symlinked Pest resolved test paths through the main checkout.
- `./vendor/bin/pint --dirty` — PASS.
