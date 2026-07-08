# REQ-041: Recursive test file finder

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.73078
**Claimed at:** 2026-07-08T20:47:55Z
**Heartbeat:** 2026-07-08T20:47:55Z
<!-- claimed-end -->

**UR:** UR-010
**Status:** in-progress
**Created:** 2026-07-09
**Layer:** package
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Shard/TestFileFinder.php, tests/Unit/Shard/TestFileFinderTest.php
**Depends on:**

## Task

Implement plan **Task 6**: create `RawPHP\Warp\Shard\TestFileFinder::find(array $paths, string $suffix = 'Test.php'): array` for recursive suffix-based test file discovery, explicit file pass-through, sorted dedupe, and `[warp] no such test path` errors.

## Context

`warp shard` and the S3 bench harness need deterministic file discovery. The result must keep the same relative/absolute form callers pass in because Pest receives the list verbatim from the CLI.

## Acceptance Criteria

- [ ] Directory inputs discover suffix-matching test files recursively and return a sorted list.
- [ ] Explicit file inputs pass through regardless of suffix and dedupe with directory discovery.
- [ ] Custom suffixes are honored.
- [ ] Trailing slashes on directory arguments are stripped.
- [ ] Missing paths throw `RuntimeException` with `[warp] no such test path`.

## Verification Steps

1. **test** `./vendor/bin/pest tests/Unit/Shard/TestFileFinderTest.php`
   - Expected: PASS for all plan Task 6 finder tests.
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.

## Integration

**Reachability:** Called by `ShardCommand` in REQ-043 and `bench/shard-spread.php` in REQ-044.

**Data dependencies:** Reads filesystem paths supplied by CLI users or bench scripts.

**Service dependencies:** Pure SPL recursive iteration under the existing Composer PSR-4 namespace `RawPHP\Warp\`.
