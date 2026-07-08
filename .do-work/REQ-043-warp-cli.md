# REQ-043: `warp` CLI with shard and timings commands

**UR:** UR-010
**Status:** backlog
**Created:** 2026-07-09
**Layer:** package
**Entry point:** `php bin/warp`, `./vendor/bin/warp shard <k>/<n>`, and `./vendor/bin/warp timings`
**Terminal state:** The CLI prints machine-clean shard file lists on stdout, diagnostics on stderr, timing summaries on demand, valid usage errors, and is registered as the package binary.
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** L
**Files:** bin/warp, src/Cli/WarpCli.php, src/Cli/ShardCommand.php, src/Cli/TimingsCommand.php, composer.json, tests/Unit/Cli/ShardCommandTest.php, tests/Unit/Cli/TimingsCommandTest.php, tests/Integration/Cli/WarpBinTest.php
**Depends on:** REQ-037, REQ-041, REQ-042

## Task

Implement plan **Task 8**: add `bin/warp`, `WarpCli`, `ShardCommand`, and `TimingsCommand`; register the Composer binary; and cover the command layer with unit and integration tests.

## Context

This is the user-facing command surface for S3. `warp shard` is designed for shell substitution into Pest, so stdout must contain only the selected files and all diagnostics must go to stderr. Empty shards intentionally return exit code 3 so CI can skip them instead of expanding to an empty Pest invocation.

## Acceptance Criteria

- [ ] `warp shard <index>/<total> [paths...]` discovers files, loads timings, prints only selected files to stdout, and warns to stderr when no timings exist.
- [ ] `warp shard` supports `--timings-dir=DIR` and `--suffix=SUFFIX`.
- [ ] Usage, missing paths, and invalid shard specs return exit code 2 with `[warp]` diagnostics.
- [ ] Empty shards return exit code 3 and print no stdout file list.
- [ ] `warp timings` merges pending data and reports test count, file count, total milliseconds, and slowest files.
- [ ] `bin/warp` resolves the autoloader in both package-development and installed-vendor layouts, is executable, and `composer.json` registers it in `bin`.

## Verification Steps

1. **test** `./vendor/bin/pest tests/Unit/Cli`
   - Expected: PASS for shard and timings command unit tests.
2. **test** `./vendor/bin/pest tests/Integration/Cli/WarpBinTest.php`
   - Expected: PASS for binary invocation behavior.
3. **runtime** `php bin/warp`
   - Expected: exits 2 and prints usage to stderr.
4. **runtime** `composer validate`
   - Expected: valid Composer manifest.
5. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.

## Integration

**Reachability:** Users run `./vendor/bin/warp shard <k>/<n> tests` or `./vendor/bin/warp timings`; the package exposes this through Composer's `bin` registration in `composer.json`.

**Data dependencies:** Reads test paths from command arguments and timing data from `.warp/timings` or `--timings-dir`.

**Service dependencies:** Consumes `TimingStore` from REQ-037, `TestFileFinder` from REQ-041, and `DurationBalancedSharder` from REQ-042.
