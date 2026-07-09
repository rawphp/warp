# REQ-068: Detect short/partial writes in TimingStore::writePending


**UR:** UR-012
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed all 1 verification checkpoints passed; commit:d7a5fe6
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php
**Depends on:**

## Task

`TimingStore::writePending()` checks the `file_put_contents()` result only for `=== false`. On a full/quota-limited filesystem a partial write returns a positive byte count (passes the `=== false` guard), and the truncated `.tmp` is then `rename()`d into `pending/` as a valid-looking batch. Verify the write was complete: compare the returned byte count against `strlen()` of the encoded payload, and on a short write treat it as a failure (do not publish the truncated file — throw/return the same error path as an outright write failure, and clean up the temp file).

## Context

Code-review finding #4 (CONFIRMED). Reconcile with UR-011/REQ-048 ("Atomic pending-batch writes and tolerant, glob-safe pending discovery") first: read `.do-work/archive/REQ-048-atomic-pending-writes.md` and confirm this short-write check aligns with (does not contradict) that REQ's accepted atomic-write design — this is closing a residual edge REQ-048 left, not reversing it. Downstream, `mergedWithPending()` silently warns and skips undecodable batches AND never unlinks them, so a truncated batch is lost forever with no error surfaced — making write-time detection the correct place to catch it.

## Acceptance Criteria

- [x] `writePending()` treats a short write (bytes written < `strlen()` of the encoded JSON) as a failure: the truncated file is NOT renamed into `pending/`, the `.tmp` is cleaned up, and the same failure signal as a `false` return is raised.
- [x] A complete write still publishes the batch atomically via the existing temp-file-then-rename path — no change to the success behaviour.
- [x] The fix does not contradict REQ-048's atomic-write design (confirmed by reading the archived REQ); note the reconciliation in the commit body.
- [x] A new `TimingStoreTest` case simulates a short write and asserts no truncated batch lands in `pending/`.

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter=TimingStore` — Expected: all pass, including a new short-write case asserting the truncated batch is not published and the failure is surfaced (not silently dropped).

## Outputs

- src/Timing/TimingStore.php — writePending now verifies the written byte count, unlinks a short-written temp file, and raises the existing write failure signal before publish.
- tests/Unit/Timing/TimingStoreTest.php — Added short-write simulation coverage proving truncated pending batches are not published.
