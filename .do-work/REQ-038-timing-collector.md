# REQ-038: In-process timing collector

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
**Files:** src/Timing/TimingCollector.php, tests/Unit/Timing/TimingCollectorTest.php
**Depends on:** REQ-037

## Task

Implement plan **Task 3**: create `RawPHP\Warp\Timing\TimingCollector` to record started/finished test pairs, compute millisecond durations rounded to three decimals, expose recorded tests through `all()`, and flush exactly once to `TimingStore`.

## Context

The collector is the in-memory bridge between PHPUnit events and `TimingStore`. It must tolerate interleaved tests, missing starts, missing file attribution, and duplicate flush calls because the extension flushes both on execution finish and through a shutdown backstop.

## Acceptance Criteria

- [ ] Started/finished pairs record the elapsed milliseconds with the correct file path.
- [ ] Interleaved tests are tracked independently.
- [ ] Finishes without starts and finishes without file attribution are ignored.
- [ ] `flush()` writes one pending batch and is idempotent.
- [ ] Empty flushes write nothing.

## Verification Steps

1. **test** `./vendor/bin/pest tests/Unit/Timing/TimingCollectorTest.php`
   - Expected: PASS for all plan Task 3 collector tests.
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.

## Integration

**Reachability:** Instantiated by `TimingExtension` in REQ-040 when `WARP_TIMINGS=1` is enabled.

**Data dependencies:** Holds per-process test id, file, and duration state in memory until flush.

**Service dependencies:** Consumes `RawPHP\Warp\Timing\TimingStore` from REQ-037.
