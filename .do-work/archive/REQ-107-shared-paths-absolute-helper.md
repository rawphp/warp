# REQ-107: One shared Paths::absolute() — kill the three drifted resolvers


**UR:** UR-017
**Status:** done
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed commit:4aa8649 — 406 pest passed (1183 assertions)
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Support/Paths.php, src/Timing/TimingStore.php, src/Shard/SuiteDiscovery.php, tests/Unit/Support/PathsTest.php, tests/Unit/Timing/TimingStoreTest.php, tests/Unit/Cli/ShardCommandTest.php, tests/Unit/Shard/SuiteDiscoveryTest.php
**Depends on:**

## Task

Fix findings 10 and 14: absolute-path detection/joining exists three times and has already drifted.

1. Add `Paths::absolute(string $path, string $base): string` to src/Support/Paths.php with the superset behavior: leading `/` or DIRECTORY_SEPARATOR is absolute, Windows drive-letter form `#^[A-Za-z]:[\\/]#` is absolute, everything else joins onto rtrim'd `$base`.
2. Replace the verbatim duplicates `ShardCommand::absolutePath()` and `SuiteDiscovery::resolve()` with calls to the helper; delete the private copies.
3. Replace `TimingStore::absolutize()` (the drifted third copy — checks only leading `/`, so `WARP_TIMINGS_DIR=C:\timings` becomes `cwd.'/C:\timings'`, a garbage dir silently created) with the helper, and move absolutization into the **TimingStore constructor** so every entry point gets it — today `TimingStoreArgumentParser` constructs `new TimingStore($dir)` raw, which both skips Windows handling and re-acquires the lazy-relative-dir bug REQ-094 fixed for the env var.

Acceptance bar per UR-017 clarification: Windows behavior is covered by unit tests with simulated drive-letter inputs; no platform-support claim, no Windows CI.

## Context

Review findings 10 (correctness: Windows env var mis-joined; --timings-dir bypasses absolutization entirely) and 14 (cleanup: triplicated resolver, one already drifted). Extends the UR-012/REQ-071 helper-consolidation precedent; the helper belongs in src/Support/Paths.php beside canonical()/normalize(). Footprint overlaps REQ-103 on ShardCommand/Paths — footprint arbitration serializes; no hard dep (independent helpers).

## Acceptance Criteria

- [x] `Paths::absolute()` handles: leading-slash absolute, `C:\...` and `C:/...` drive-letter absolute (returned unchanged), and relative joins onto a base with/without trailing separator — each covered by a unit test
- [x] `ShardCommand::absolutePath()`, `SuiteDiscovery::resolve()`, and `TimingStore::absolutize()` are deleted; grep shows the drive-letter regex exists exactly once, in Paths
- [x] `new TimingStore('relative/dir')` and `--timings-dir=relative/dir` resolve against the construction-time cwd, byte-identical to `WARP_TIMINGS_DIR=relative/dir` resolution
- [x] Simulated `WARP_TIMINGS_DIR=C:\timings` produces the drive path itself, not `cwd.'/C:\timings'`
- [x] All existing tests green: `./vendor/bin/pest`

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce finding 10 first: TimingStore::fromEnv with a simulated drive-letter dir → assert store dir equals the drive path (must fail pre-fix producing the garbage join); and `--timings-dir=rel` + chdir between parse and use → assert dir pinned at parse-time cwd
   - Expected: red-then-green on both entry points
2. **test** Paths::absolute unit matrix (slash, drive-letter both separators, relative join, trailing-separator base)
   - Expected: all cases pass
3. **test** ShardCommand --configuration and SuiteDiscovery resolution behave identically pre/post refactor (existing tests unchanged)
   - Expected: no behavioral drift; handoff CLI-arg → resolved path localized
4. **test** `./vendor/bin/pest`
   - Expected: all green

## Outputs

- src/Support/Paths.php — added `Paths::absolute(path, base)`: the single shared absolute detector/joiner (leading-slash / DIRECTORY_SEPARATOR / Windows drive-letter absolute; else join onto rtrim'd base). Drive-letter regex now lives here exactly once
- src/Timing/TimingStore.php — deleted drifted private `absolutize()`; constructor calls `Paths::absolute($dir, getcwd() ?: '.')` so every entry point (fromEnv, raw `new TimingStore`, withRoot/withWarner) absolutizes identically — fixes finding-10 `--timings-dir` bypass and Windows drive-letter mis-join
- src/Shard/SuiteDiscovery.php — deleted private `resolve()`; now calls `Paths::absolute()`
- tests/Unit/Support/PathsTest.php — `Paths::absolute()` matrix + codebase-wide single-regex assertion
- tests/Unit/Timing/TimingStoreTest.php — drive-letter regression (red pre-fix), constructor-absolutization, absolutize()-deletion checks
- tests/Unit/Shard/SuiteDiscoveryTest.php — absolute-config-path test + resolve()-deletion check
- tests/Unit/Cli/ShardCommandTest.php — `--timings-dir=relative` pinned at parse-time cwd across an intervening chdir
