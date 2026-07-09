# REQ-052: `warp merge` command + uniform CLI error handling

**UR:** UR-011
**Status:** done
**Created:** 2026-07-09
**Layer:** package
**Entry point:**
**Terminal state:**
**Parent:** REQ-046
**Closure proof:** checkpoint_log:passed all 3 verification checkpoints passed commit:bff2a65
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Cli/WarpCli.php, src/Cli/MergeCommand.php, src/Cli/TimingsCommand.php, src/Timing/TimingStore.php, tests/Unit/Cli/MergeCommandTest.php, tests/Unit/Cli/TimingsCommandTest.php
**Depends on:** REQ-051

## Task

1. **New `warp merge` subcommand**: add `MergeCommand` (registered in `WarpCli::run()`'s dispatch, src/Cli/WarpCli.php:14-23) that calls `TimingStore::mergeToDisk()` (REQ-051). Supports `--timings-dir=<dir>`; reports how many batches were merged; exit 0 on success (including "nothing to merge"), exit 2 on error with a `[warp]`-prefixed stderr message. This is now the ONLY CLI operation that writes to the timings dir.
2. **Uniform error handling** (finding #10): `TimingsCommand::run()` (src/Cli/TimingsCommand.php:30) currently lets `RuntimeException` escape as an uncaught fatal (exit 255) where `ShardCommand` catches and exits 2. Wrap TimingsCommand's store access in the same `catch (InvalidArgumentException|RuntimeException)` → stderr + exit 2 pattern used at src/Cli/ShardCommand.php:54-58, and give `MergeCommand` the same treatment from day one.
3. Update `WarpCli`'s usage/help output to list `merge`.

## Context

Review findings #3 (clarified contract: explicit merge step) and #10 (TimingsCommand uncaught exception → stack trace and exit 255 for the identical condition ShardCommand reports cleanly). With REQ-051 making `load()` read-only, `warp timings`/`warp shard` no longer hit lock errors at all — but error-handling parity is still required for unreadable dirs/corrupt JSON, and `warp merge` inherits the lock-failure path.

## Acceptance Criteria

- [x] `warp merge` merges pending batches to disk (pending files gone, timings.json updated) and prints a batch count; running it again immediately exits 0 reporting nothing to merge.
- [x] `warp merge` against an unwritable timings dir exits 2 with a `[warp]`-prefixed stderr message — no stack trace.
- [x] `warp timings` against a store whose `timings.json` is unreadable/corrupt exits 2 with a `[warp]`-prefixed stderr message — no stack trace, no exit 255.
- [x] `warp` usage output lists the `merge` subcommand.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Cli/MergeCommandTest.php tests/Unit/Cli/TimingsCommandTest.php`
   - Expected: merge behaviour and error-parity tests pass.
2. **runtime** In a temp dir with recorded pending batches: `php bin/warp merge --timings-dir=<dir> && php bin/warp merge --timings-dir=<dir>`
   - Expected: first run reports N batches merged (exit 0); second reports nothing to merge (exit 0); `pending/` is empty after the first.
3. **test** `./vendor/bin/pest`
   - Expected: full suite green.

## Integration

**Reachability:** New subcommand `merge` dispatched from `WarpCli::run()` (src/Cli/WarpCli.php), reachable via `bin/warp` (bin/warp:15) — same entry as existing `shard`/`timings` subcommands.

**Data dependencies:** Timings dir (`timings.json`, `pending/*.json`, `merge.lock`) via `TimingStore::mergeToDisk()`.

**Service dependencies:** `TimingStore` (REQ-051) and `Support\FileLock` (REQ-047); mirrors the option parsing and error-exit conventions of src/Cli/ShardCommand.php and src/Cli/TimingsCommand.php.

## Outputs

- `src/Cli/MergeCommand.php` — Added `warp merge` with `--timings-dir`, batch-count/no-op output, and clean `[warp]` exit-2 error handling.
- `src/Cli/WarpCli.php` — Registered `merge` dispatch and listed it in usage output.
- `src/Cli/TimingsCommand.php` — Wrapped timing-store access in the same clean exception handling pattern as `ShardCommand`.
- `src/Timing/TimingStore.php` — Made `mergeToDisk()` return the merged pending batch count and throw explicit `[warp]` read/decode/write/publish/delete errors.
- `tests/Unit/Cli/MergeCommandTest.php` — Covered merge success, repeated no-op, lock failure clean exit, unknown args, and usage listing.
- `tests/Unit/Cli/TimingsCommandTest.php` — Covered corrupt `timings.json` clean exit without a stack trace.
