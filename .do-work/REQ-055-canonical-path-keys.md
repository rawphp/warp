# REQ-055: Canonicalize timing keys and shard lookups; warn on total key mismatch

**UR:** UR-011
**Status:** backlog
**Created:** 2026-07-09
**Layer:** package
**Entry point:**
**Terminal state:**
**Parent:** REQ-054
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 3
**Size:** M
**Files:** src/Timing/TestFileResolver.php, src/Shard/TestFileFinder.php, src/Cli/ShardCommand.php, tests/Unit/Timing/TestFileResolverTest.php, tests/Unit/Shard/TestFileFinderTest.php, tests/Unit/Cli/ShardCommandTest.php
**Depends on:**

## Task

Establish one canonical path form — project-root-relative with no leading `./` — and enforce it at both boundaries (finding #1):

1. **Record side**: `TestFileResolver` (src/Timing/TestFileResolver.php:23-27) already strips `getcwd()`; normalize the result through a shared canonicalizer (realpath-based, strips the root prefix, no `./`, forward slashes) so keys are deterministic.
2. **Read side**: in `ShardCommand` (src/Cli/ShardCommand.php:46), canonicalize every discovered file to the same form **for weight lookup and plan computation**, printing the caller's original spelling only on output — or simpler, print canonical paths (decide and document; determinism across machines is the requirement, per finding #1's divergent-plans scenario).
3. **Mismatch warning**: the existing warning fires only when `$totals === []` (src/Cli/ShardCommand.php:49). Add: when totals are non-empty but ZERO discovered files match any stored key, print a stderr warning stating that recorded timings exist but match no discovered file (likely path-form or stale-artifact mismatch) and that packing degraded to count-balanced.
4. Extract the canonicalizer as a small shared helper (e.g. `Support\Paths::canonical(string $path, string $root): string`) so resolver, finder consumers, and future callers share one definition.

Clean break: previously recorded timings.json keys that don't match the canonical form are simply unmatched (decisions.md 2026-07-05); the new warning makes that visible.

## Context

Review finding #1 — the top-ranked bug: `array_intersect_key` in DurationBalancedSharder.php:26 does exact-string matching between cwd-relative stored keys and as-given discovered paths (`TestFileFinder` documents "paths come back in the form they were given", src/Shard/TestFileFinder.php:14-17). Every spelling mismatch silently degrades the whole feature and can produce divergent plans across CI nodes. The README version-lock note (all nodes on the same warp version during the key-format change) lands in REQ-062.

## Acceptance Criteria

- [ ] `warp shard 1/4 tests`, `warp shard 1/4 ./tests`, and `warp shard 1/4 <absolute path to tests>` against the same recorded timings produce byte-identical stdout.
- [ ] Weights are actually applied in all three spellings: a test with skewed recorded durations asserts the duration-balanced (not count-balanced) partition for each spelling.
- [ ] With non-empty recorded totals and zero matching discovered files, stderr contains a warning naming the mismatch; exit code and stdout plan are unchanged (still count-balanced fallback).
- [ ] `TestFileResolver` emits canonical keys (no `./` prefix, root-relative) — asserted directly in its unit test.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Timing/TestFileResolverTest.php tests/Unit/Shard/TestFileFinderTest.php tests/Unit/Cli/ShardCommandTest.php`
   - Expected: canonicalization, spelling-equivalence, and mismatch-warning tests pass.
2. **runtime** In a fixture project with recorded timings: `php bin/warp shard 1/2 tests > a.txt; php bin/warp shard 1/2 ./tests > b.txt; diff a.txt b.txt`
   - Expected: diff is empty (identical plans across spellings — the ShardCommand → sharder handoff uses canonical keys).
3. **test** `./vendor/bin/pest`
   - Expected: full suite green.

## Integration

**Reachability:** Record path: `TimingExtension` → `TestFileResolver::resolve()` (src/Timing/TimingExtension.php:61); read path: `warp shard` → `ShardCommand::run()` → `TestFileFinder::find()` (src/Cli/ShardCommand.php:46).

**Data dependencies:** `timings.json` key format (clean break to canonical form) read via `TimingStore::fileTotals()`.

**Service dependencies:** `DurationBalancedSharder::assign()`'s `array_intersect_key` lookup (src/Shard/DurationBalancedSharder.php:26) consumes the canonical keys; new `Support\Paths` helper shared with future callers.
