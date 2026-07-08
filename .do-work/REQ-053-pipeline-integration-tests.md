# REQ-053: Integration tests — read-only artifact flow and exit-code contract

**UR:** UR-011
**Status:** backlog
**Created:** 2026-07-09
**Layer:** integration
**Entry point:**
**Terminal state:**
**Parent:** REQ-046
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** M
**Files:** tests/Integration/Cli/WarpBinTest.php
**Depends on:** REQ-052

## Task

Extend `tests/Integration/Cli/WarpBinTest.php` to prove the revised pipeline contract end-to-end through `bin/warp`:

1. **Read-only artifact**: build a timings dir with `timings.json` + unmerged `pending/*.json`, `chmod 0555` it, and assert `warp shard` exits 0 with a plan that reflects the pending data (in-memory overlay), and `warp timings` exits 0 — no writes attempted.
2. **Exit-code contract**: assert `warp shard` yields exit 0 (non-empty shard), exit 3 (empty shard, e.g. more shards than files), and exit 2 (error: nonexistent path) — the contract the corrected README recipe (REQ-062) branches on.
3. **Recipe semantics**: run the corrected CI guard pattern (exit-3-tolerant, exit-2-fatal) in an `sh -e` subshell against each of the three exit codes and assert the shell exits 0 / 0-with-skip / non-zero respectively.
4. **Merge flow**: record → `warp merge` → assert pending emptied and a subsequent read-only `warp shard` still produces the identical plan.

## Context

Review findings #2 and #3: the documented CI pattern silently skipped a shard's entire test run on any error, and shard machines mutated restored artifacts. These integration tests pin the new contract so regressions in either direction (writes creeping back into the read path, or exit-code drift) fail CI. `tests/Integration/Cli/WarpBinTest.php` already exercises `bin/warp` end-to-end and is the natural home.

## Acceptance Criteria

- [ ] A read-only (0555) timings dir with pending batches: `warp shard` exits 0 and its plan reflects pending data; the dir's mtime/content is unchanged after the run.
- [ ] Exit codes 0, 3, and 2 are each produced and asserted via real `bin/warp` invocations.
- [ ] The corrected guard pattern, executed via `sh -e -c`, runs the test command on exit 0, skips on exit 3 with overall success, and fails the script on exit 2.
- [ ] Post-`warp merge`, `pending/` is empty and shard output is byte-identical to the pre-merge overlay plan.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Integration/Cli/WarpBinTest.php`
   - Expected: all new integration cases green (tests must run, not skip — decisions.md 2026-07-07).
2. **test** `./vendor/bin/pest`
   - Expected: full suite green.

## Integration

**Reachability:** Drives the real CLI binary `bin/warp` (bin/warp:15 → `WarpCli::run()`), the same entry CI pipelines use.

**Data dependencies:** Temp timings dirs (timings.json, pending/*.json) constructed per-test; no shared fixtures.

**Service dependencies:** Exercises `ShardCommand`, `TimingsCommand`, `MergeCommand`, and `TimingStore` (REQ-047…REQ-052) as a black box.
