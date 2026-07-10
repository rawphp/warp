# REQ-093: Config-dir canonical root for timing keys with root stamp

**UR:** UR-016
**Status:** backlog
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Size:** L
**Files:** src/Timing/TimingExtension.php, src/Timing/TimingStore.php, src/Timing/TestFileResolver.php, src/Cli/ShardCommand.php, tests/Unit/Timing/TimingStoreTest.php, tests/Unit/Cli/ShardCommandTest.php, tests/Integration/Timing/TimingCaptureTest.php, README.md
**Depends on:**

## Task

Make the phpunit configuration file's directory the canonical root for timing keys on both sides of the pipeline, and stamp that root into the timings artifact so mismatches fail loudly:

1. **Extension side:** `TimingExtension::bootstrap()` already receives PHPUnit's `Configuration` object — read the configuration source path and use `dirname(configFile)` as the canonical root instead of `getcwd()` (TimingExtension.php:36). Fall back to `getcwd()` only when no XML configuration exists (pure CLI-path runs), and record which mode was used.
2. **Artifact schema:** stamp the canonical root (absolute, realpath'd) into `timings.json` and into pending batch payloads. This is a clean-break schema change — bump the store VERSION; no migration needed (standing decision 2026-07-05: zero public users pre-release).
3. **Shard side:** `warp shard` already canonicalizes against `dirname(config)` when `--configuration` is passed (ShardCommand.php:86, suiteRoot at :161-171); keep that, and on load compare the artifact's stamped root against the shard-time canonical root. On mismatch, print a loud, specific stderr error naming both roots and exit non-zero — never silently degrade to count-balanced for a root mismatch (silent degradation is exactly what finding 1 exposed).
4. Update the README's sharding/recording sections to state the canonical-root rule.

## Context

Finding 1 (UR-016), verified CONFIRMED: TimingExtension keys timings relative to `getcwd()` while `warp shard --configuration=app/phpunit.xml` keys discovered files relative to `app/`. Recording via `pest -c app/phpunit.xml` from the repo root produces keys like `app/tests/FooTest.php`; shard produces `tests/FooTest.php`; zero keys intersect and sharding silently degrades to count-balanced with only a stderr warning. The printed shard paths are also relative to the config dir, so feeding them back to phpunit from the repo root fails. Clarification (question gate): config-dir root on both sides, root stamped into the artifact, mismatches detected loudly.

## Acceptance Criteria

- [ ] TimingExtension derives its canonical root from the Configuration source path (`dirname` of the phpunit.xml actually used) when one exists; `getcwd()` only when no XML config is loaded
- [ ] `timings.json` and pending batch payloads carry the canonical root; the store VERSION is bumped
- [ ] Recording timings via a run whose cwd differs from the config dir (e.g. `pest -c sub/phpunit.xml` from the parent dir) then running `warp shard N/M --configuration=sub/phpunit.xml` from the parent dir produces duration-balanced shards — the keys intersect
- [ ] `warp shard` against an artifact whose stamped root differs from the shard-time canonical root exits non-zero with a stderr message naming both roots
- [ ] Shard output paths remain resolvable by phpunit when invoked from the same cwd with the same `--configuration` value (document the resolution rule in the README)

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce the original bug first: a test that records timings with a fixture config in a subdirectory while cwd is the parent (mirroring the finding-1 scenario) and asserts the shard command's keys now intersect (this test must fail against the pre-fix behavior)
   - Expected: test passes post-fix; the same scenario on the old code yielded zero key intersection
2. **test** `./vendor/bin/pest --filter=TimingStoreTest`
   - Expected: all pass, including new root-stamp schema assertions
3. **test** `./vendor/bin/pest --filter=ShardCommandTest`
   - Expected: all pass, including the loud root-mismatch exit
4. **test** `./vendor/bin/pest`
   - Expected: full suite green (schema bump ripples through integration tests)
