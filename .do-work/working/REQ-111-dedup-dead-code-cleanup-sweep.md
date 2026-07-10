# REQ-111: Dedup and dead-code cleanup sweep

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.dw17
**Claimed at:** 2026-07-10T06:50:16Z
**Heartbeat:** 2026-07-10T06:50:16Z
<!-- claimed-end -->

**UR:** UR-017
**Status:** in-progress
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** L
**Files:** src/Timing/TimingExtension.php, src/Timing/TestFileResolver.php, src/Support/FileLock.php, bench/shard-spread.php, src/Cli/WarpCli.php, src/Cli/ShardCommand.php, src/Shard/TestFileFinder.php, tests/Unit/Timing/TimingExtensionTest.php, tests/Unit/Timing/TestFileResolverTest.php, tests/Unit/Support/FileLockTest.php, tests/Unit/Cli/ShardCommandTest.php
**Depends on:**

## Task

Pure-duplication and dead-code cleanups from the review (findings 16, 18, 19, 20, 21, 22), folded into one trailing refactor REQ per the UR-012/REQ-071 and UR-013/REQ-079 precedents. No behavior changes except the documented usage-string fix.

1. **TimingExtension subscriber dedup (16):** six anonymous subscriber classes redeclare the identical `(TimingCollector, string $root)` constructor; Skipped/Errored/MarkedIncomplete bodies are identical. Collapse via a shared terminate closure/factory (the ExecutionFinished subscriber already takes a Closure — same pattern). Coordinate with REQ-105's Errored change if it has landed (its errored-unprepared branch must survive the dedup).
2. **TestFileResolver (18):** `cacheableClass()` + `$cacheableByClass` duplicate `fileForClass()` — cacheable ≡ `fileForClass($c) !== null`. Remove the second ReflectionClass per class and the fourth parallel cache.
3. **FileLock dead code (19):** the `error_get_last()` fallback after the scoped handler is unreachable (handler returns true, error_clear_last ran first). Delete the dead branch; the handler alone captures the message.
4. **bench dedup (20):** bench/shard-spread.php re-implements `ShardCommand::canonicalFiles()` verbatim including the exception and warning strings. Extract one shared helper (public method or Support class) and call it from both; the bench must exercise the same canonicalization the CLI uses.
5. **Usage strings (21):** both `warp shard` usage lines (WarpCli.php and ShardCommand.php) omit the supported `--configuration=` option — add it; prefer a single shared usage constant so the two strings cannot drift again.
6. **Suffix shadow copy (22):** `ShardCommand` initializes `$suffix = ['Test.php', '.phpt']`, a copy of the private `TestFileFinder::DEFAULT_SUFFIXES`, plus a `$suffixOption` mirror variable. Make the constant public (or expose a default) and collapse to one nullable option variable.

## Context

Review cleanup findings, all CONFIRMED by verification. Trailing Priority-1 refactor with no hard deps (UR-013 precedent: "folded into trailing cleanup REQ with Priority 1 and no fake deps") — footprint arbitration serializes overlaps with REQ-103/105/107/108 naturally. Pure refactors: test steps only are sufficient where behavior is unchanged.

## Acceptance Criteria

- [ ] TimingExtension declares the shared constructor/terminate logic once; the three terminal-event subscriber bodies are not duplicated (grep-checkable)
- [ ] TestFileResolver has no `cacheableClass` method and at most three static caches; each uncached class is reflected at most once per resolve
- [ ] FileLock contains no `error_get_last()` call; its failure diagnostics are byte-identical for a failing @fopen (existing FileLockTest messages unchanged)
- [ ] bench/shard-spread.php contains no copy of the canonicalFiles loop or the duplicated warning/exception strings; grep shows each string exactly once in src/
- [ ] `warp` and `warp shard` usage output both document `--configuration=` and are sourced from one shared definition
- [ ] TestFileFinder's default suffixes are referenced (not copied) by ShardCommand; changing the constant changes shard behavior in a test
- [ ] Full suite green: `./vendor/bin/pest` — zero behavior diffs outside the usage strings

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest`
   - Expected: all green with no test-expectation changes except usage-string assertions
2. **runtime** `php bin/warp 2>&1; php bin/warp shard 2>&1`
   - Expected: both usage outputs include `--configuration=`; identical shard syntax line from one source
3. **runtime** `grep -rn "error_get_last" src/ ; grep -c "test path is outside project root" -r src/ bench/`
   - Expected: no error_get_last in FileLock; the exception string appears exactly once across src/ and bench/
4. **test** `php bench/shard-spread.php --help` or the bench smoke run per bench/shard-spread.sh
   - Expected: bench still runs and reports spread using the shared canonicalization
