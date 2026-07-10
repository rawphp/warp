# REQ-098: PHPUnit-exact configuration filename probing in SuiteDiscovery

**UR:** UR-016
**Status:** done
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed commit:79b0cfc
**Criteria approved:** agent-drafted
**Size:** S
**Files:** src/Shard/SuiteDiscovery.php, tests/Unit/Shard/SuiteDiscoveryTest.php
**Depends on:**

## Task

Extend `SuiteDiscovery::configurationPath()` (SuiteDiscovery.php:98) to probe exactly the filenames PHPUnit's `XmlConfigurationFileFinder` probes, in exactly its order: `phpunit.xml`, then `phpunit.dist.xml`, then `phpunit.xml.dist` (vendor/phpunit/phpunit/src/TextUI/Configuration/Cli/XmlConfigurationFileFinder.php:60-62). Today `phpunit.dist.xml` is never found and precedence is wrong when both dist variants exist.

## Context

Finding 12 (UR-016), verified CONFIRMED: a project using the documented `phpunit.dist.xml` convention gets MissingConfigurationException and warp falls back to a bare `tests/` + `Test.php` walk — ignoring `<exclude>` entries, custom suffix attributes, extra suite directories, and `<file>` entries. The shard set silently diverges from what phpunit runs: excluded files execute and files in non-`tests/` suite dirs never run on any shard. When both `phpunit.dist.xml` and `phpunit.xml.dist` exist, phpunit reads the former while warp shards from the latter.

## Acceptance Criteria

- [x] A root containing only `phpunit.dist.xml` is discovered (no fallback warning, suites parsed from it)
- [x] A root containing both `phpunit.dist.xml` and `phpunit.xml.dist` resolves to `phpunit.dist.xml` (PHPUnit's precedence)
- [x] A root containing `phpunit.xml` still wins over both dist variants
- [x] The probe order is asserted against the vendored `XmlConfigurationFileFinder` source order in a test comment or fixture, so future PHPUnit changes surface as a test-review prompt

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce the original bug first: fixture root with only `phpunit.dist.xml` — assert `configurationPath()` finds it (must fail pre-fix with MissingConfigurationException)
   - Expected: config discovered, suites parsed
2. **test** Precedence fixtures: (`phpunit.dist.xml` + `phpunit.xml.dist`) → dist.xml wins; (`phpunit.xml` + both) → phpunit.xml wins
   - Expected: matches PHPUnit's XmlConfigurationFileFinder order
3. **test** `./vendor/bin/pest --filter=SuiteDiscoveryTest`
   - Expected: all green

## Outputs

- src/Shard/SuiteDiscovery.php — configurationPath() probes phpunit.xml, phpunit.dist.xml, phpunit.xml.dist in PHPUnit-exact order
- tests/Unit/Shard/SuiteDiscoveryTest.php — bug-repro, precedence, and live vendored-source parity tests
