# REQ-041: Recursive test file finder

**UR:** UR-010
**Status:** done
**Created:** 2026-07-09
**Layer:** package
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** Recursive file discovery implemented and verified by focused and full package suites.
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

- [x] Directory inputs discover suffix-matching test files recursively and return a sorted list.
- [x] Explicit file inputs pass through regardless of suffix and dedupe with directory discovery.
- [x] Custom suffixes are honored.
- [x] Trailing slashes on directory arguments are stripped.
- [x] Missing paths throw `RuntimeException` with `[warp] no such test path`.

## Verification Steps

1. **test** `./vendor/bin/pest tests/Unit/Shard/TestFileFinderTest.php`
   - Expected: PASS for all plan Task 6 finder tests.
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.

## Integration

**Reachability:** Called by `ShardCommand` in REQ-043 and `bench/shard-spread.php` in REQ-044.

**Data dependencies:** Reads filesystem paths supplied by CLI users or bench scripts.

**Service dependencies:** Pure SPL recursive iteration under the existing Composer PSR-4 namespace `RawPHP\Warp\`.

## Outputs

- `src/Shard/TestFileFinder.php` — deterministic recursive suffix-based test file discovery with explicit file pass-through and missing-path errors.
- `tests/Unit/Shard/TestFileFinderTest.php` — coverage for recursion, sorting, dedupe, custom suffixes, trailing slashes, and missing paths.

## Verification Evidence

- `./vendor/bin/pest tests/Unit/Shard/TestFileFinderTest.php` — PASS, 5 tests / 6 assertions.
- `./vendor/bin/pest` — PASS, 165 tests / 382 assertions after replacing the worktree-only `vendor` symlink with a local install because symlinked Pest resolved test paths through the main checkout.
- `./vendor/bin/pint --dirty` — PASS.
