# REQ-058: Path-unit — CLI timings-dir resolution honors WARP_TIMINGS_DIR; strict flag parsing

**UR:** UR-011
**Status:** backlog
**Created:** 2026-07-09
**Layer:** package
**Entry point:** `WARP_TIMINGS_DIR=/some/dir ./vendor/bin/warp shard|timings|merge ...` with no `--timings-dir` flag.
**Terminal state:** All three subcommands read/merge the exact directory the recording extension wrote to (env var honored, one canonical resolver); unknown `--flags` are rejected loudly by every subcommand instead of being swallowed as paths.
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Cli/ShardCommand.php, src/Cli/TimingsCommand.php, src/Cli/MergeCommand.php, tests/Unit/Cli/ShardCommandTest.php, tests/Unit/Cli/TimingsCommandTest.php, tests/Unit/Cli/MergeCommandTest.php
**Depends on:** REQ-052, REQ-055

## Task

1. **One resolver** (finding #6 / C1): delete the hardcoded `'.warp/timings'` defaults in `ShardCommand` (src/Cli/ShardCommand.php:24) and `TimingsCommand` (src/Cli/TimingsCommand.php:18) — and `MergeCommand` from REQ-052 — and default all three through `TimingStore::fromEnv()` (src/Timing/TimingStore.php:17-22), which already honors `WARP_TIMINGS_DIR`. `--timings-dir=<dir>` remains the explicit override. The default-directory knowledge lives in exactly one place.
2. **Strict flag parsing** (over-cap finding A3): `ShardCommand` currently routes any unrecognized token into `$paths` (src/Cli/ShardCommand.php:34-35) while `TimingsCommand` rejects unknown args. Unify: any argument starting with `--` that is not a recognized option causes exit 2 with `[warp] unknown option: <token>` in every subcommand; positional arguments keep their current meaning.

## Context

Review finding #6 and over-cap C1: recording (via `TimingExtension` → `fromEnv()`) and reading defaulted to different locations, so a team recording with `WARP_TIMINGS_DIR=/ci/cache` got silent count-balanced sharding from `warp shard`. Over-cap A3: `warp shard 1/8 --timigs-dir=/x` treated the typo'd flag as a test path, producing a misleading "no such test path" error, while `warp timings` rejected unknown args — inconsistent contracts.

## Acceptance Criteria

- [ ] With `WARP_TIMINGS_DIR` set and no `--timings-dir` flag, `warp shard`, `warp timings`, and `warp merge` all operate on the env-specified directory (tests set the env var and assert the store contents are found).
- [ ] `--timings-dir=<dir>` overrides the env var in all three subcommands.
- [ ] The string literal `.warp/timings` appears in exactly one place in src/ (`TimingStore::fromEnv()`), verified by grep.
- [ ] `warp shard 1/8 --timigs-dir=/x` exits 2 with stderr `[warp] unknown option: --timigs-dir=/x` (not a "no such test path" error); the same unknown-option behaviour holds for `timings` and `merge`.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Cli/ShardCommandTest.php tests/Unit/Cli/TimingsCommandTest.php tests/Unit/Cli/MergeCommandTest.php`
   - Expected: env-resolution and unknown-option tests pass for all three subcommands.
2. **runtime** `WARP_TIMINGS_DIR=<fixture-dir> php bin/warp timings`
   - Expected: reports the fixture's recorded timings (env → store handoff works without --timings-dir).
3. **runtime** `php bin/warp shard 1/2 --bogus-flag=1 tests; echo $?`
   - Expected: exit 2, stderr contains `unknown option`.
4. **test** `./vendor/bin/pest` and `grep -rn "\.warp/timings" src/`
   - Expected: full suite green; grep shows the literal only in TimingStore::fromEnv().

## Integration

**Reachability:** `bin/warp` → `WarpCli::run()` → `ShardCommand` / `TimingsCommand` / `MergeCommand` argument parsing — the same CLI surface CI pipelines call.

**Data dependencies:** `WARP_TIMINGS_DIR` env var and the timings dir contents via `TimingStore::fromEnv()` (src/Timing/TimingStore.php:17-22).

**Service dependencies:** `TimingStore::fromEnv()` becomes the single resolver; depends on REQ-052 (MergeCommand exists) and REQ-055 (ShardCommand edits serialized by file footprint).
