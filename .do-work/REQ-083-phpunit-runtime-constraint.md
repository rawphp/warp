# REQ-083: Declare PHPUnit runtime compatibility

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
**Size:** S
**Files:** composer.json, composer.lock, tests/Unit/ComposerConstraintTest.php
**Depends on:**

## Task

Add an explicit runtime Composer constraint for PHPUnit so incompatible installs fail during dependency resolution instead of at `warp shard` or `WARP_TIMINGS=1` runtime.

## Context

Confirmed finding 3: runtime `src/` code depends on PHPUnit internals such as TextUI configuration loading, FileIterator facade behavior, and `Configuration::hasExcludeFilter()`, but `composer.json` only requires PHP. Clarification: do not build a PHPUnit 10 compatibility layer now; require the PHPUnit versions Warp actually supports. Current locked development dependency is PHPUnit 12.5.30, so the public runtime constraint should allow PHPUnit 11.1+ compatible majors rather than only the currently locked dev version.

## Acceptance Criteria

- [ ] `composer.json` runtime `require` includes `phpunit/phpunit` with a constraint that rejects PHPUnit 10 and allows PHPUnit 11.1+ compatible supported majors.
- [ ] `composer.lock` is refreshed consistently with the new runtime requirement and the existing dev dependency stack.
- [ ] A focused test or static assertion proves the package constraint rejects PHPUnit 10-era installs and is not only present in `require-dev`.
- [ ] Composer validation succeeds without weakening the existing PHP `^8.4` requirement or package metadata.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="ComposerConstraint|WarpBin"`
   - Expected: package constraint coverage passes and any CLI bootstrap smoke tests still run against the locked PHPUnit version.
2. **build** `composer validate --strict`
   - Expected: Composer reports a valid package definition with the new runtime PHPUnit constraint.
3. **test** `./vendor/bin/pest`
   - Expected: full suite green under the locked dependency set.
