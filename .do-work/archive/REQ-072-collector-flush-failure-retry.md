# REQ-072: Don't mark collector flushed before the write succeeds

**UR:** UR-013
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed checkpoints:2 commit:65ba6cd
**Criteria approved:** agent-drafted
**Priority:** 3
**Size:** S
**Files:** src/Timing/TimingCollector.php, tests/Unit/Timing/TimingCollectorTest.php
**Depends on:**

## Task

`TimingCollector::flush()` (src/Timing/TimingCollector.php:65) sets `$this->flushed = true` BEFORE calling `$store->writePending()`. If the write throws (ENOSPC/EACCES, short write, failed rename, `random_bytes` failure), the exception unwinds but the collector is already marked flushed — so the `register_shutdown_function` backstop in `TimingExtension` sees `hasFlushed() === true` and never retries. The entire worker's timing batch is silently lost even when the failure was transient. Reorder so the flushed flag is only set after `writePending()` returns successfully, keeping flush idempotent on success while allowing the backstop to retry after a failed write.

## Context

Code-review finding #3 (CONFIRMED). The double-flush guard exists because the `ExecutionFinished` subscriber and the shutdown backstop may both call flush — idempotency on SUCCESS must be preserved (a successful flush must never write a second batch). The fix changes only the failure path: a throwing write leaves the collector un-flushed so the backstop's later call retries.

## Acceptance Criteria

- [x] When `writePending()` throws, `hasFlushed()` remains `false` afterwards, and a subsequent `flush()` call retries the write (verified by a test with a store stub/failing dir that throws once, then succeeds).
- [x] When `writePending()` succeeds, `hasFlushed()` returns `true` and a second `flush()` call performs no second write (existing idempotency preserved — exactly one pending batch on disk).
- [x] The `$complete` argument passed on the retry is the retry caller's value, not a stale one (no caching of the failed attempt's flag).

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter=TimingCollector` — Expected: all pass, including a new case reproducing the original bug path: first flush throws (unwritable timings dir), collector is NOT marked flushed, second flush succeeds and publishes exactly one batch.
2. **test** `./vendor/bin/pest` — Expected: full suite green (flush is shared machinery between the ExecutionFinished subscriber and the shutdown backstop).

## Outputs

- src/Timing/TimingCollector.php — Marks collector flushed only after `writePending()` succeeds.
- tests/Unit/Timing/TimingCollectorTest.php — Adds regression coverage for failed flush retry, success idempotency, and retry complete flag.
