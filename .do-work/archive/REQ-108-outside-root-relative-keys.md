# REQ-108: Allow outside-root files in all discovery modes with root-relative ../ keys


**UR:** UR-017
**Status:** done
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed commit:0d7300c — 391 pest passed
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Support/Paths.php, src/Cli/ShardCommand.php, src/Timing/TimingExtension.php, src/Timing/TestFileResolver.php, bench/shard-spread.php, tests/Unit/Support/PathsTest.php, tests/Unit/Cli/ShardCommandTest.php, tests/Integration/Timing/TimingCaptureTest.php, tests/Unit/Timing/TestFileResolverTest.php
**Depends on:** REQ-103

## Task

Fix finding 8 and unify the key domain (UR-017 question-gate decision):

1. **One policy for all three discovery modes.** Today `allowOutsideRoot` is true only for config-driven discovery; the explicit-path and no-phpunit.xml fallback branches leave it false, so the same symlinked or ../-located test file shards fine with a phpunit.xml present but exits 2 ("test path is outside project root") without one. Allow outside-root files in every mode; the exit-2 abort path for outside-root files is removed.
2. **Root-relative ../ keys everywhere.** `Paths::canonical()`'s allowOutside escape currently emits ABSOLUTE machine-specific keys for outside-root files — keys that never match across machines, the exact failure class the root handshake prevents. Change the canonical key form for outside-root files to root-relative with `../` segments (e.g. `../shared/tests/FooTest.php`), computed on both the record side (TimingExtension/TestFileResolver) and the shard side (ShardCommand, bench) so there is ONE key domain. The `allowOutside` boolean parameter should disappear or become vestigial — one key policy, no per-caller special case.

Depends on REQ-103: the key-form change builds on the redesigned root handshake (shared resolver, root-scoped artifact) so keys are relativized against one agreed root.

## Context

Review finding 8 plus the altitude observation that the allowOutside flag created two key domains (root-relative inside, absolute outside). Key form chosen at the UR-017 question gate: relative ../ keys — stable across machines with the same layout. Note: existing artifacts holding absolute outside-root keys will simply stop matching (count-weighted fallback per file) — acceptable pre-release, consistent with the standing clean-break decisions (2026-07-05, UR-011).

## Acceptance Criteria

- [x] `warp shard 1/2 ../shared/tests` (explicit outside-root path) and the tests/-fallback with a symlinked tests dir both shard successfully — no "outside project root" abort in any mode (reproduces finding 8 pre-fix)
- [x] An outside-root test file records the key `../<relative path>` in the pending batch and the shard side computes the identical key — timings recorded on one machine match on another machine with the same layout but a different absolute prefix (simulated via two roots in tests)
- [x] Inside-root keys are byte-identical to current behavior (no churn of the existing key domain)
- [x] The per-caller allowOutside special-case parameter is gone from Paths::canonical's public surface (one key policy)
- [x] All existing tests green: `./vendor/bin/pest`

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce finding 8 first: explicit outside-root path → shard succeeds (must fail pre-fix with exit 2 "outside project root")
   - Expected: red-then-green in explicit-path and fallback modes
2. **test** Key parity: record timings for an outside-root file in a child run, shard from the same root → the file's weight is applied (key matched); repeat with a different absolute prefix, same layout → still matched
   - Expected: ../ keys match across simulated machines; handoff record-key → shard-key localized
3. **test** Inside-root key snapshot: existing key fixtures unchanged
   - Expected: zero diffs
4. **test** `./vendor/bin/pest`
   - Expected: all green

## Outputs

- src/Support/Paths.php — `canonical()` drops the `allowOutside` param and computes root-relative `../` keys for outside-root files via common-ancestor walk (one key domain); added `isInside()` for boolean inside/outside checks
- src/Cli/ShardCommand.php — removed `allowOutsideRoot` bookkeeping; outside-root files no longer abort (exit 2) in any discovery mode
- src/Timing/TestFileResolver.php — uses `Paths::isInside()` for reflection-vs-reported-file disambiguation; `canonical()` call drops `allowOutside`
- src/Timing/TimingExtension.php — `fileFor()` drops `allowOutside: true` (now default)
- bench/shard-spread.php — comment updated for `../` relativization
- tests/Unit/Support/PathsTest.php, tests/Unit/Cli/ShardCommandTest.php, tests/Unit/Timing/TestFileResolverTest.php, tests/Integration/Timing/TimingCaptureTest.php — finding-8 reproductions, cross-machine `../` key-parity (unit + real child-process integration), inside-root unchanged, allowOutside-removed reflection assertion
