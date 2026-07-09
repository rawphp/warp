# REQ-088: Attribute inherited tests to concrete files

**UR:** UR-015
**Status:** backlog
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
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

- [ ] A unit-level resolver test proves a concrete PHPUnit test class with inherited test methods resolves to the concrete class file, not the abstract base or trait declaring file.
- [ ] A child timing-capture integration test with multiple concrete subclasses of a shared abstract/trait test records timings under each concrete test file.
- [ ] Pest `__filename` handling still takes precedence for Pest-generated eval classes.
- [ ] Resolver caching remains root-aware and does not leak one concrete class path into another class/root pair.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="TestFileResolver|TimingCapture"`
   - Expected: resolver and child timing-capture tests pass, including inherited-method attribution to concrete files.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green; Pest attribution and root-aware cache behavior remain intact.
