# REQ-088: Attribute inherited tests to concrete files

**UR:** UR-015
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Closure proof:** checkpoint_log:passed all 2 verification checkpoints passed; commit:7a74834
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Timing/TestFileResolver.php, src/Timing/TimingExtension.php, tests/Unit/Timing/TestFileResolverTest.php, tests/Integration/Timing/TimingCaptureTest.php
**Depends on:**

## Task

Fix timing file attribution for classic PHPUnit tests whose test methods are inherited from an abstract base class or trait, so timings are recorded against each concrete `*Test.php` file.

## Context

Confirmed finding 8: `TimingExtension` receives `TestMethod::file()` from PHPUnit, which can be the declaring base/trait file instead of the concrete test class file. Current `TestFileResolver` corrects Pest `__filename` cases but falls back to the reported file for classic PHPUnit classes. Ideate found the resolver is already the right reuse point; prove the inherited-method bug still fails, then extend the resolver path.

## Acceptance Criteria

- [x] A unit-level resolver test proves a concrete PHPUnit test class with inherited test methods resolves to the concrete class file, not the abstract base or trait declaring file.
- [x] A child timing-capture integration test with multiple concrete subclasses of a shared abstract/trait test records timings under each concrete test file.
- [x] Pest `__filename` handling still takes precedence for Pest-generated eval classes.
- [x] Resolver caching remains root-aware and does not leak one concrete class path into another class/root pair.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="TestFileResolver|TimingCapture"`
   - Expected: resolver and child timing-capture tests pass, including inherited-method attribution to concrete files.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green; Pest attribution and root-aware cache behavior remain intact.

## Outputs

- src/Timing/TestFileResolver.php — Prefers concrete class files inside the active root after Pest `__filename` and before PHPUnit's reported declaring file.
- tests/Unit/Timing/TestFileResolverTest.php — Adds inherited-method resolver coverage and preserves Pest/root-aware cache behavior.
- tests/Integration/Timing/TimingCaptureTest.php — Adds child-process timing capture coverage for inherited base and trait methods across concrete classes.
