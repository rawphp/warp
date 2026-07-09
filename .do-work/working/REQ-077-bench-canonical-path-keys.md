# REQ-077: Bench must canonicalize file paths before weight lookup

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.23132
**Claimed at:** 2026-07-09T09:41:25Z
**Heartbeat:** 2026-07-09T09:41:25Z
<!-- claimed-end -->

**UR:** UR-013
**Status:** in-progress
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** bench/shard-spread.php, tests/Integration/Cli/WarpBinTest.php
**Depends on:**

## Task

`bench/shard-spread.php` passes RAW `TestFileFinder::find()` paths as `$files` (line 31) but CANONICAL root-relative keys from `fileTotals()` as `$totals` (line 24). Run the bench with an absolute suite path (or from a subdirectory) and `weights()`'s `array_intersect_key` comes up empty → every file collapses to the fallback weight 1.0 → the "warp LPT" column shows a meaningless equal round-robin spread while appearing to succeed — silently defeating the S3 gate report. Canonicalize the discovered file paths against the app root via `Paths::canonical` the way `ShardCommand::canonicalFiles` does (src/Cli/ShardCommand.php:120-136) before computing weights/plan — reuse, don't duplicate (decided at question gate; REQ-070 consolidation precedent applies if a small shared helper is the cleanest reuse).

## Context

Code-review finding #6. The bench script is the S3 gate evidence path (`bench/shard-spread.sh` drives it against a real app), so a silently-wrong report is worse than a crash here. Consider also surfacing the existing "recorded timings match no discovered file" mismatch warning (ShardCommand.php:96 has the pattern) so a future path-form regression fails loudly instead of quietly falling back.

## Acceptance Criteria

- [ ] Running the bench with an absolute suite path against a timings store keyed root-relative resolves recorded weights (not fallback) — the LPT column differs from the count-based column when recorded durations are skewed (reproduces then fixes the finding-#6 scenario).
- [ ] Running the bench with a relative suite path from the app root produces identical output to before the change (no regression on the documented usage).
- [ ] When recorded timings match zero discovered files after canonicalization, the bench prints a mismatch warning to stderr instead of silently reporting an equal spread.

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **runtime** From a temp fixture app dir with a seeded `.warp/timings/timings.json` (root-relative keys, skewed durations): `php bench/shard-spread.php .warp/timings 2 "$(pwd)/tests"` — Expected: LPT column reflects the seeded skew (not an equal 1.0-weight round-robin); handoff finder→weights resolves recorded values.
2. **test** `./vendor/bin/pest` — Expected: full suite green.
