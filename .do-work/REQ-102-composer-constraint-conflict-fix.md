# REQ-102: Fix php-file-iterator constraint conflict and declare composer/semver

**UR:** UR-017
**Status:** backlog
**Created:** 2026-07-10
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

Two composer-manifest fixes (findings 1 and 13):

1. Relax the direct requirement `phpunit/php-file-iterator` from `^6.0.1` to `^5.0 || ^6.0`. The current pin makes the advertised `"phpunit/phpunit": "^11.1 || ^12"` unsatisfiable for every PHPUnit 11.x consumer (all 11.x releases require file-iterator ^5.x — verified via `composer why-not phpunit/phpunit 11.5.0`). `SebastianBergmann\FileIterator\ExcludeIterator` exists since file-iterator 5.0 with an identical constructor signature, so no 5.1 floor is needed.
2. Add `composer/semver` to `require-dev`. `tests/Unit/ComposerConstraintTest.php` imports `Composer\Semver\Semver` but the package arrives only transitively via orchestra/testbench → sidekick/canvas; a testbench dependency-graph change would fatal the constraint-guard test itself.

Extend `ComposerConstraintTest` to guard the new invariant: the declared phpunit constraint and the declared php-file-iterator constraint must be co-satisfiable for both PHPUnit 11 and 12 (e.g. assert the file-iterator constraint admits a ^5 version when the phpunit constraint admits ^11).

## Context

Review finding 1 (most severe — deterministic install failure for PHPUnit 11 consumers; the ^11.1 half of the support claim is dead) and finding 13, merged into one REQ because both touch composer.json + ComposerConstraintTest.php in one commit. Windows/platform note not applicable here; run `composer update phpunit/php-file-iterator composer/semver --with-all-dependencies` to regenerate the lock minimally.

## Acceptance Criteria

- [ ] `composer.json` requires `phpunit/php-file-iterator: ^5.0 || ^6.0` and `composer why-not phpunit/phpunit 11.5.0` no longer reports a conflict caused by rawphp/warp's file-iterator constraint
- [ ] `composer/semver` appears in `require-dev` and `composer why composer/semver` lists rawphp/warp as a direct dependent
- [ ] ComposerConstraintTest asserts phpunit ^11 and the file-iterator constraint are co-satisfiable, and fails if the file-iterator constraint is re-tightened to ^6-only
- [ ] `composer validate` passes and the lock file is regenerated consistently (`composer install` from clean state succeeds)
- [ ] Full suite green: `./vendor/bin/pest`

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce finding 1 first: assert co-satisfiability of the phpunit and file-iterator constraints in ComposerConstraintTest (must fail against the current `^6.0.1` pin, pass after relaxing)
   - Expected: red-then-green on the constraint-conflict test
2. **runtime** `composer why-not phpunit/phpunit 11.5.0`
   - Expected: no conflict line naming rawphp/warp's php-file-iterator requirement
3. **runtime** `composer validate && composer why composer/semver`
   - Expected: valid manifest; composer/semver shows rawphp/warp (require-dev) as a dependent
4. **test** `./vendor/bin/pest`
   - Expected: all green
