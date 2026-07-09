---
ur: UR-014
received: 2026-07-09
status: intake
---

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
