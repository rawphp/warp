---
ur: UR-014
received: 2026-07-09
status: captured
classification: bug-fix
layers_in_scope: []
layer_decisions: {}
reqs:
  - { id: REQ-080, layer: none, integration_confidence: n/a }
acknowledged_partials: []
---

<!-- capture-summary-start -->
## Capture summary (2026-07-09)

| Item | Value |
|---|---|
| Classification | bug-fix |
| Layers in scope | (none — bug-fix) |
| Layer decisions | (none — all covered) |
| REQs generated | 1 |

| REQ | Layer | Integration confidence |
|---|---|---|
| REQ-080 | none | n/a |
<!-- capture-summary-end -->

# UR-014: User Request

## Request

run intake on the exit-3 skip-guard conflation

## Referenced finding (quoted verbatim from the branch code review that this request points at)

> **File:** src/Cli/ShardCommand.php:107 — category: correctness, verdict: CONFIRMED
>
> **Summary:** Zero discovered test files exits with the same code 3 as a legitimately empty shard, so the README's documented rc==3 skip guard turns "discovery found nothing" into all-green CI jobs that run zero tests.
>
> **Failure scenario:** phpunit.xml points at an existing-but-empty tests directory (or all suites are filtered out): SuiteDiscovery/TestFileFinder return [] without error, every shard exits 3 with 'more shards than test files', and the documented guard (rc 3 → echo skip; exit 0) makes all N CI jobs pass while the entire suite silently stopped executing. Zero discovered files needs a distinct failure exit code.
>
> Verifier evidence: neither SuiteDiscovery nor TestFileFinder throws on an empty result set (SuiteDiscovery.php:53 "no such test suite directory" requires the dir to be missing, so an existing-but-empty dir returns []), DurationBalancedSharder::plan([], ...) returns empty bins without error, and ShardCommand.php:107-110 emits exit 3 with "more shards than test files" for any empty shard. The README's documented guard (lines 280-289: `if [ "$rc" -eq 3 ]; then echo "[warp] shard is empty; skipping pest"; exit 0; fi`) therefore converts a suite that discovered nothing into N passing CI jobs, and WarpBinTest.php ("exits 3 for an empty shard", line 212; guard test at line 279) codifies exactly this conflated behavior with no distinct code for zero discovered files.

## Clarifications

**Q:** The finding says zero discovered files "needs a distinct failure exit code" — distinct from 3, but which code?
**A:** Reuse exit 2. README's exit-code table already defines 2 as "Usage, discovery, timings, or other shard error", and the documented guard hard-fails on any non-0/non-3 code, so no consumer guard changes are needed. *(inferred, confirmed)*

**Q:** Does the adjacent all-zero-weights sharder finding (same "CI collapses" symptom, different mechanism) belong in this UR?
**A:** No — out of scope. This UR covers only the exit-3 skip-guard conflation finding recorded verbatim in the Request. *(inferred, confirmed)*

**Q:** Where should the zero-discovered-files guard live — ShardCommand, or thrown from SuiteDiscovery/TestFileFinder?
**A:** ShardCommand. One guard clause after discovery + canonicalization (`$files === []` → stderr message + exit 2). Narrowest blast radius; bench/shard-spread.php (which also calls TestFileFinder) is untouched.

**Q:** Should the guard cover both zero-file entry paths (phpunit.xml/fallback discovery AND explicit paths matching nothing, e.g. wrong --suffix)?
**A:** Both branches. The guard sits after both discovery paths converge (post-canonicalization), so either path producing zero files exits 2.
