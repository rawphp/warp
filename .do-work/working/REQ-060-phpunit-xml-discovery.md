# REQ-060: Path-unit — phpunit.xml testsuite-driven shard discovery

<!-- claimed-start -->
**Claimed by:** codex-main
**Claimed at:** 2026-07-09T02:28:03Z
**Heartbeat:** 2026-07-09T02:28:03Z
<!-- claimed-end -->

**UR:** UR-011
**Status:** in-progress
**Created:** 2026-07-09
**Layer:** package
**Entry point:** `./vendor/bin/warp shard k/n` with no explicit paths, in a project whose phpunit.xml declares testsuites (multiple roots, custom suffixes, `<file>` entries, `<exclude>` blocks).
**Terminal state:** The shard file universe equals exactly the set of files PHPUnit/Pest would run: excluded files are never sharded, custom-suffix and out-of-tree suite files are always assigned to exactly one shard; explicit path arguments still override discovery.
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** L
**Files:** src/Shard/SuiteDiscovery.php, src/Cli/ShardCommand.php, tests/Unit/Shard/SuiteDiscoveryTest.php, tests/Unit/Cli/ShardCommandTest.php
**Depends on:** REQ-055, REQ-058

## Task

Replace the hardcoded default discovery (`tests` dir + `Test.php` suffix, src/Cli/ShardCommand.php:24-25,46 and src/Shard/TestFileFinder.php:43) with phpunit.xml-driven discovery (finding #9, clarified: full support):

1. New `Shard\SuiteDiscovery` that loads the project's phpunit.xml via **PHPUnit's own configuration loader** (`PHPUnit\TextUI\XmlConfiguration\Loader` / the same API `TimingExtension::bootstrap()` already receives a parsed Configuration from) and enumerates the suite file list honoring: multiple `<directory>` roots, per-directory `suffix=` attributes, `<file>` entries, and `<exclude>` blocks.
2. `ShardCommand` default behaviour (no path arguments): use `SuiteDiscovery` when a phpunit.xml/phpunit.xml.dist exists at the project root; fall back to the current `tests`/`Test.php` heuristic (with a stderr note) when none exists.
3. Explicit path arguments bypass suite discovery entirely (current `TestFileFinder` behaviour, canonicalized per REQ-055).
4. Support `--configuration=<file>` to point at a non-default phpunit.xml, mirroring PHPUnit's own flag.
5. Discovered files feed the canonical-key pipeline from REQ-055 unchanged.

## Context

Review finding #9: any project whose testsuites differ from the hardcoded guess gets a shard plan that diverges from the real suite — excluded files are distributed and run anyway, files outside `tests/` or with a nonstandard suffix are assigned to no shard and silently never run in sharded CI while every shard exits green. Clarification: full phpunit.xml support in this UR, accepting it is the largest REQ of the batch.

## Acceptance Criteria

- [ ] Fixture phpunit.xml with two `<directory>` roots (one with `suffix="Check.php"`), one `<file>` entry, and an `<exclude>` block: `SuiteDiscovery` returns exactly the files PHPUnit would run (excluded file absent, custom-suffix and `<file>` entries present).
- [ ] `warp shard` with no paths in a fixture project shards exactly the SuiteDiscovery universe; an excluded file appears in no shard; every suite file appears in exactly one shard across k=1..n.
- [ ] `warp shard` with no paths and no phpunit.xml falls back to the `tests`/`Test.php` heuristic and prints a stderr note.
- [ ] Explicit paths (`warp shard 1/2 tests/Unit`) bypass suite discovery.
- [ ] `--configuration=custom.xml` is honored.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Shard/SuiteDiscoveryTest.php tests/Unit/Cli/ShardCommandTest.php`
   - Expected: suite-enumeration fixtures (multi-root, custom suffix, file entries, excludes) all pass.
2. **runtime** In this repo: `php bin/warp shard 1/1 && ./vendor/bin/pest $(php bin/warp shard 1/1)`
   - Expected: shard 1/1 lists exactly the files phpunit.xml's testsuites cover, and pest runs them green — the discovery → runner handoff loses nothing.
3. **test** `./vendor/bin/pest`
   - Expected: full suite green.

## Integration

**Reachability:** Default (path-less) invocation of `warp shard` via `bin/warp` → `ShardCommand::run()`; `--configuration` flag parsed alongside existing options (REQ-058's strict parser).

**Data dependencies:** Reads phpunit.xml / phpunit.xml.dist at the project root (this repo's phpunit.xml registers the timing extension and declares the tests suite).

**Service dependencies:** PHPUnit's XmlConfiguration loader (already a dependency — `TimingExtension::bootstrap()` receives a parsed Configuration, src/Timing/TimingExtension.php:30-31); `TestFileFinder` (src/Shard/TestFileFinder.php) remains for explicit paths; canonical keys from REQ-055.
