# REQ-039: Test-file resolver for Pest and classic PHPUnit tests

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.73078
**Claimed at:** 2026-07-08T20:41:41Z
**Heartbeat:** 2026-07-08T20:41:41Z
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
**Files:** src/Timing/TestFileResolver.php, tests/Unit/Timing/TestFileResolverTest.php
**Depends on:**

## Task

Implement plan **Task 4**: create `RawPHP\Warp\Timing\TestFileResolver::resolve(string $className, string $reportedFile, string $root): ?string` so Pest-generated classes use their static `$__filename`, classic PHPUnit tests use the reported file, paths are made project-relative, and unattributable files return `null`.

## Context

Spike fact #3 says `TestMethod::file()` reports Pest tests as eval code, while Pest-generated classes carry the real file in `public static $__filename`. The timings artifact must use project-relative file paths and skip anything outside the project root.

## Acceptance Criteria

- [ ] Classic PHPUnit classes resolve from the reported file when it is inside the project root.
- [ ] Pest-generated classes prefer static `$__filename` over eval-reported paths.
- [ ] Eval paths without a Pest filename return `null`.
- [ ] Files outside the project root return `null`.
- [ ] A trailing slash on the root is tolerated.

## Verification Steps

1. **test** `./vendor/bin/pest tests/Unit/Timing/TestFileResolverTest.php`
   - Expected: PASS for all plan Task 4 resolver tests.
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.

## Integration

**Reachability:** Called by `TimingExtension` in REQ-040 for each PHPUnit `Finished` test event.

**Data dependencies:** Reads class metadata and reported file paths from PHPUnit event test objects.

**Service dependencies:** Pure PHP helper under the existing Composer PSR-4 namespace `RawPHP\Warp\` declared in `composer.json`.
