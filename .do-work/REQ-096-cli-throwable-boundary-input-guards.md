# REQ-096: CLI Throwable boundary, graceful corrupt-timings degrade, input guards

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
**Files:** src/Cli/WarpCli.php, src/Cli/ShardCommand.php, src/Cli/MergeCommand.php, src/Cli/TimingsCommand.php, src/Timing/TimingStore.php, tests/Unit/Cli/ShardCommandTest.php, tests/Unit/Cli/MergeCommandTest.php, tests/Unit/Cli/TimingsCommandTest.php, tests/Unit/Timing/TimingStoreTest.php, tests/Integration/Cli/WarpBinTest.php
**Depends on:**

## Task

Fix the CLI's exception policy and the two crash inputs behind it (question-gate decision: hoist, don't patch in place):

1. **One error boundary:** wrap command dispatch in `WarpCli::run` with a single `try/catch (Throwable)` that writes the message to the *injected* stderr stream and returns exit 2. Delete the four duplicated `catch (InvalidArgumentException|RuntimeException)` blocks (ShardCommand.php:63-67 and :120-124, MergeCommand.php:22-26, TimingsCommand.php:23-27).
2. **Graceful corrupt-timings degrade (finding 6):** `readMerged()` on undecodable JSON currently throws, hard-failing every shard in a CI matrix; missing-file and wrong-version paths already return empty silently. Change undecodable timings.json to warn-and-return-empty so `warp shard` degrades to count-balanced with a warning — consistent policy across all three artifact-corruption modes (clarified decision: never hard-fail the matrix over a corrupt timings artifact).
3. **Finite-ms guard (finding 7):** `apply()` accepts any `is_numeric` ms (`"1e999"` → INF), which later makes `json_encode(JSON_THROW_ON_ERROR)` throw JsonException — uncaught today, and the poison batch is never cleaned so merges fail forever. Reject non-finite ms values during entry sanitization (treat the entry as invalid, same as non-numeric), so the poison batch takes the existing undecodable/junk cleanup path instead of wedging the store.
4. **Shard-total bounds (finding 20):** validate the parsed shard spec before any allocation — total must be ≥ 1 and ≤ a sane ceiling (e.g. 10,000); reject with the standard `[warp] ...` exit-2 diagnostic. Today `1/2000000000` dies with an uncatchable memory-allocation fatal and `1/99999999999999999999` throws an uncaught ValueError from `array_fill` (exit 255).

Validation stays at the source so errors are diagnostic; the Throwable boundary is the backstop, not the primary interface.

## Context

Findings 6, 7, 20 (UR-016), all verified CONFIRMED (20 empirically against the real binary). Shared root: the exception policy is a hand-copied catch block that misses JsonException (extends Exception) and ValueError (extends Error). The four-way duplication was independently flagged as a confirmed round-1 cleanup. Grouped per the question-gate decision and UR-012 grouping precedent.

## Acceptance Criteria

- [ ] `WarpCli::run` catches Throwable from any command, writes `[warp] `-prefixed message to the injected stderr stream, and returns 2; the four per-command catch blocks are gone
- [ ] A truncated/undecodable `timings.json` makes `warp shard i/N` print a warning and shard count-balanced with exit 0 (matrix keeps running); missing and wrong-version behavior unchanged
- [ ] A pending batch with `"ms": "1e999"` (or any non-finite numeric) is rejected during sanitization: `warp merge` completes with exit 0, the poison batch is cleaned up via the junk path, and subsequent merges succeed
- [ ] `warp shard 1/2000000000 tests` and `warp shard 1/99999999999999999999 tests` both exit 2 with a bounds diagnostic naming the limit — no fatal, no stack trace
- [ ] `warp shard 0/4` and `warp shard 5/4` still produce their existing range diagnostics
- [ ] Full CLI test files green

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **runtime** Reproduce finding 20 first: `php bin/warp shard 1/99999999999999999999 tests; echo "exit=$?"`
   - Expected: exit=2 with a `[warp]` bounds message (pre-fix: uncaught ValueError, exit 255)
2. **runtime** Write a pending batch containing `"ms":"1e999"` into a fixture timings dir, run `php bin/warp merge --timings-dir=<fixture>; echo "exit=$?"` twice
   - Expected: both invocations exit 0; the poison batch is gone after the first (pre-fix: JsonException fatal, exit 255, batch persists)
3. **runtime** Truncate a fixture `timings.json` mid-file, run `php bin/warp shard 1/2 <fixture-tests> --timings-dir=<fixture>; echo "exit=$?"`
   - Expected: exit 0, count-balanced shard output, stderr warning about undecodable timings (pre-fix: exit 2)
4. **test** `./vendor/bin/pest --filter=ShardCommandTest && ./vendor/bin/pest --filter=MergeCommandTest && ./vendor/bin/pest --filter=TimingsCommandTest && ./vendor/bin/pest --filter=WarpBinTest`
   - Expected: all green
