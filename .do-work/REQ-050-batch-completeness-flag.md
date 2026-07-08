# REQ-050: Batch completeness flag — partial crash-flush batches must not supersede complete data

**UR:** UR-011
**Status:** backlog
**Created:** 2026-07-09
**Layer:** package
**Entry point:**
**Terminal state:**
**Parent:** REQ-046
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Timing/TimingStore.php, src/Timing/TimingCollector.php, src/Timing/TimingExtension.php, tests/Unit/Timing/TimingStoreTest.php, tests/Unit/Timing/TimingCollectorTest.php
**Depends on:** REQ-049

## Task

Two changes to the batch/merge semantics:

1. **Completeness flag** (finding #4): extend the pending-batch payload to carry a completeness marker (e.g. `{"complete": bool, "tests": {...}}`). `TimingExtension`'s normal end-of-run flush writes `complete: true`; the `register_shutdown_function` backstop (src/Timing/TimingExtension.php:82) writes `complete: false` (it only fires meaningfully after a fatal). In `TimingStore::apply()`, only `complete: true` batches use supersede-by-file semantics (drop all prior entries for covered files); incomplete batches merge per-test-id only, so a 2-test crash flush can no longer wipe a file's 48 other recorded entries.
2. **Single-pass apply** (over-cap finding E3): while rewriting `apply()` (src/Timing/TimingStore.php:141-149), replace the per-batch `array_filter` over the entire merged test map with a file-grouped index so superseding a file is O(1) per covered file instead of O(all tests) per batch.

Clean break on the pending payload format is allowed (decisions.md 2026-07-05); REQ-049 already changed the filename format.

## Context

Review finding #4: a recording run that fatals mid-file flushes a partial batch via the shutdown backstop, and apply()'s whole-file supersession then deletes every previously recorded complete entry for that file — the file's weight collapses (e.g. 5000ms → 200ms) and shard packing blows out one shard's wall clock. Over-cap finding E3: apply() is O(W·T) across W worker batches.

## Acceptance Criteria

- [ ] Normal-completion flush produces `complete: true` batches; the shutdown backstop path produces `complete: false` batches (unit-testable by invoking the collector/extension flush paths directly).
- [ ] A test seeds timings.json with 3 entries for FileA (total 3000ms), merges an incomplete batch containing 1 FileA test at 100ms, and asserts the other 2 entries survive (per-test merge, no whole-file supersede).
- [ ] The same scenario with `complete: true` asserts the old supersede-by-file behaviour still holds.
- [ ] `apply()` no longer runs `array_filter` over the full test map per batch; merging N batches touching disjoint files does not rescan unrelated entries (structure/complexity change verified by reading the implementation; behavioural equivalence by the existing merge tests).

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Timing/TimingStoreTest.php tests/Unit/Timing/TimingCollectorTest.php`
   - Expected: completeness-flag and partial-merge tests pass.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green.

## Integration

**Reachability:** `TimingExtension`'s finished-run flush and `register_shutdown_function` backstop (src/Timing/TimingExtension.php:76-82) are the two writers; `TimingStore::apply()` is the consumer during merge.

**Data dependencies:** Pending-batch JSON payload shape (clean break: adds the `complete` flag) and `timings.json` entries keyed by test id with `file` fields.

**Service dependencies:** Builds on REQ-049's timestamped batch files in src/Timing/TimingStore.php (hard dependency).
