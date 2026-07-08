# REQ-046: Path-unit — crash/race-safe timings pipeline with read-only consumers

**UR:** UR-011
**Status:** backlog
**Created:** 2026-07-09
**Layer:** none
**Entry point:** `WARP_TIMINGS=1 ./vendor/bin/pest` records timings; any machine (including one restoring a read-only `.warp/timings` CI artifact) then runs `./vendor/bin/warp shard k/n` or `warp timings`; `warp merge` compacts pending batches to disk.
**Terminal state:** Recorded timings survive worker crashes, concurrent merges, multi-run accumulation, and glob-hostile paths; `warp shard`/`warp timings` never write to the timings dir and succeed on a read-only artifact; `warp merge` is the only disk-merging operation.
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** S
**Files:**
**Depends on:** REQ-047, REQ-048, REQ-049, REQ-050, REQ-051, REQ-052, REQ-053

## Task

Closure REQ for the timings-pipeline path. This REQ owns no code: it closes when its child REQs (lock helper, atomic writes, recording-order merge, batch completeness, read-only load, `warp merge` command, integration coverage) are all committed and the integration suite proves the end-to-end path.

## Context

Review findings #3, #4, #5, #7, #8, #10 and over-cap finding C3 all live in the record → merge → read pipeline (`TimingStore`, `TimingCollector`, `TimingExtension`, CLI read paths). The clarified contract (UR-011 Clarifications): `warp shard`/`warp timings` are read-only and overlay unmerged pending batches in memory; disk merges happen only via an explicit `warp merge`. Storage-format clean breaks are allowed (decisions.md 2026-07-05).

## Acceptance Criteria

- [ ] All child REQs (REQ-047 … REQ-053) are committed.
- [ ] `./vendor/bin/pest` passes 100% including the new integration tests from REQ-053.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest`
   - Expected: full suite green, including `tests/Integration/Cli/WarpBinTest.php` read-only-artifact and exit-code-contract cases.
