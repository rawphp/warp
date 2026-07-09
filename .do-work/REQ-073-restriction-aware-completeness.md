# REQ-073: Restricted runs must flush incomplete batches

**UR:** UR-013
**Status:** backlog
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 3
**Size:** M
**Files:** src/Timing/TimingExtension.php, tests/Integration/Timing/TimingCaptureTest.php
**Depends on:**

## Task

A filtered run (`pest --filter=testA` with `WARP_TIMINGS=1`) flushes `complete=true`, so `TimingStore::apply()`'s file-supersede path wipes sibling tests' stored timings for that file (src/Timing/TimingStore.php:240 — a full run records FileX::testA + testB; a later `--filter=testA` run supersedes FileX down to testA alone, under-weighting it in the sharder). The store cannot distinguish "test deleted" from "test filtered out" at merge time, so fix at the source: `TimingExtension::bootstrap` receives the PHPUnit `Configuration` — when a **method/group/path restriction** is applied (`--filter`, `--group`/`--exclude-group`, explicit file/dir CLI arguments), hold a "restricted" flag and flush `complete=false` (observed timings upsert; nothing supersedes).

**Decided semantics (clarified at question gate):** a plain `--testsuite` selection is NOT a restriction — it fully observes every file it touches, so per-file supersede stays correct and stale-ID pruning keeps working in suite-split CI (`--testsuite=Unit` / `--testsuite=Integration` jobs). Only method/group/path filters force `complete=false`.

## Context

Code-review finding #1 (top-severity: silent timing-data loss through an ordinary dev workflow). Reconcile with REQ-050 (batch completeness flag) and REQ-069 (completeness under paratest) FIRST — read both archived REQs; this narrows when `complete=true` is claimed without weakening either guarantee: partial crash-flush batches still never supersede (REQ-050), and unrestricted paratest worker flushes still prune stale IDs (REQ-069). UR-013 ideate flagged the starvation risk of over-broad restriction detection — the testsuite carve-out above is the decided trade-off; note the reconciliation in the commit body.

## Acceptance Criteria

- [ ] With a method filter active (simulated restricted Configuration), the flushed pending batch carries `complete: false`; after merge, a previously-stored sibling test ID for the same file still exists with its original ms (reproduces then fixes the finding-#1 scenario).
- [ ] With explicit path arguments as the restriction, the flushed batch likewise carries `complete: false`.
- [ ] With only a `--testsuite` selection (no filter/group/path restriction), the flushed batch carries `complete: true` and stale-ID pruning for fully-observed files still fires (REQ-069 regression guard).
- [ ] An unrestricted full run still flushes `complete: true` (existing behavior unchanged; existing REQ-050/REQ-069 tests pass).

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="TimingCapture|TimingExtension|TimingStore"` — Expected: all pass, including a new case reproducing the original bug path: full-run batch merged, then a restricted-run batch merged, and the sibling test's timing survives.
2. **test** `./vendor/bin/pest` — Expected: full suite green (completeness gates shared supersede machinery).
