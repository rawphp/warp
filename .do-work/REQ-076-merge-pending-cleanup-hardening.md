# REQ-076: Merge pending-file cleanup must not wedge or leak

**UR:** UR-013
**Status:** backlog
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php, tests/Unit/Cli/MergeCommandTest.php
**Depends on:** REQ-075

## Task

Two failure modes leave `mergeToDisk()`'s pending-file cleanup broken:

1. **Unlink wedge (finding #5):** after `timings.json` is atomically published, an `unlink()` failure on one merged pending file throws (src/Timing/TimingStore.php:89), so every future `warp merge` re-reads the survivors, hits the same undeletable file, and throws again — permanently wedged in CI. **Decided at question gate: warn and continue** — log to stderr via `Stderr::write`, keep deleting the rest, return success. This is safe only because pending-timestamp ordering makes the surviving already-merged batch re-apply BEFORE newer data on the next merge — add a test pinning that ordering assumption (old complete batch + newer batch: after a second merge, the newer data wins).
2. **Junk-batch leak (finding #8):** undecodable or decodable-but-non-array pending batches are skipped in `mergedWithPending()` (src/Timing/TimingStore.php:180-188) but never added to `$mergedPending`, so `mergeToDisk()` never deletes them — they are re-parsed and re-warned on every shard/timings/merge invocation forever. Fix: `mergeToDisk()` deletes junk batches (with a stderr warning naming the file) **only inside the merge lock** — `load()` stays strictly read-only per the UR-011 standing decision ("warp shard/timings are read-only; disk merge only via explicit warp merge").

## Task scope note

Both changes are the same concern — pending-file lifecycle in the merge path — and share the same method and tests; captured as one REQ per the UR-012 grouping precedent.

## Context

Code-review findings #5 and #8. Read archived REQ-048 (atomic pending writes / tolerant discovery) and REQ-051 (read-only load) first — junk deletion must not leak into the read-only overlay path, and warn-and-continue must not weaken merge's success contract (published data is already safe when cleanup runs). Depends on REQ-075: same file/method (mergeToDisk) — serialized per the TimingStore hard-dep precedent.

## Acceptance Criteria

- [ ] An unlink failure on one merged pending file no longer throws: the remaining pending files are still deleted, a `[warp]` warning naming the stuck file goes to stderr, and `mergeToDisk()` returns the merged count (reproduces then fixes the finding-#5 wedge).
- [ ] A test pins the re-apply ordering assumption: a surviving old complete batch plus a newer batch merge with the newer data winning after the second merge (timestamp order).
- [ ] An undecodable pending batch and a decodes-to-scalar batch are both deleted by `mergeToDisk()` (with stderr warnings) and do not reappear on the next merge (reproduces then fixes the finding-#8 leak).
- [ ] `load()` performs zero writes/deletes when encountering junk batches (read-only guarantee preserved — REQ-051 tests pass; junk skipped with a warning as today).

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="TimingStore|MergeCommand"` — Expected: all pass, including new unlink-failure, ordering-pin, and junk-deletion cases.
2. **test** `./vendor/bin/pest` — Expected: full suite green (merge path shared with CLI commands).
