# REQ-080: Exit 2 when discovery finds zero test files

**UR:** UR-014
**Status:** backlog
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Cli/ShardCommand.php, tests/Unit/Cli/ShardCommandTest.php, tests/Integration/Cli/WarpBinTest.php, README.md, CHANGELOG.md
**Depends on:**

## Task

Add a zero-discovered-files guard to `ShardCommand::run` so that discovering no test files at all is reported as a failure (exit 2), distinct from a legitimately empty shard (exit 3). Insert the guard after both discovery branches converge — immediately after `self::canonicalFiles(...)` (src/Cli/ShardCommand.php:91) — so it covers phpunit.xml/fallback discovery AND explicit path arguments that match nothing:

```php
if ($files === []) {
    fwrite($stderr, "[warp] no test files discovered - nothing to shard\n");

    return 2;
}
```

Update the README shard exit-code documentation so exit 2 explicitly covers "no test files discovered", and add a CHANGELOG entry.

## Context

Confirmed code-review finding (UR-014, verbatim in input.md): neither SuiteDiscovery nor TestFileFinder errors on an empty result set, `DurationBalancedSharder` returns empty bins without error, and ShardCommand.php:107-110 emits exit 3 ("more shards than test files") for any empty shard. The README's documented rc==3 guard (`echo skip; exit 0`) therefore converts "discovery found nothing" into N all-green CI jobs that ran zero tests.

Clarifications (input.md): reuse exit 2 (README already defines it as "Usage, discovery, timings, or other shard error"; the documented guard hard-fails on non-0/non-3, so no consumer guard changes). Guard lives in ShardCommand only — narrowest blast radius; bench/shard-spread.php, which also calls TestFileFinder, is untouched. Both zero-file entry paths are covered. The adjacent all-zero-weights sharder finding is out of scope for this UR.

Reuse (ideate.md Connector): the fix follows ShardCommand's existing `fwrite($stderr, ...); return 2;` error idiom (lines 61-65, 101-105). New tests belong in the existing exit-code contract suites — ShardCommandTest ("returns 3 and prints nothing when the shard is empty", line 349) and WarpBinTest ("exits 3 for an empty shard", line 212; sh -e guard contract, line 279) — reusing their fixture/subprocess helpers. Both pinned tests use files ≥ 1 fixtures, so they must remain green unchanged.

## Acceptance Criteria

- [ ] `warp shard <i>/<n>` with no path arguments, where phpunit.xml (or the tests/ fallback) discovery resolves to zero test files (e.g. an existing-but-empty suite directory), exits 2 with stderr containing `no test files discovered` and empty stdout — previously this exited 3.
- [ ] `warp shard <i>/<n> <path>` with explicit paths matching zero files (existing-but-empty directory, or a `--suffix=` that matches nothing) exits 2 through the same guard — previously this exited 3.
- [ ] Legitimately empty shards (discovered files ≥ 1, shard index beyond the file count) still exit 3: the existing tests at tests/Unit/Cli/ShardCommandTest.php:349, tests/Integration/Cli/WarpBinTest.php:212, and the sh -e guard-contract test at WarpBinTest.php:279 pass unmodified.
- [ ] README's shard exit-code documentation states that zero discovered files is an exit-2 error (distinct from the exit-3 empty-shard skip), and CHANGELOG.md records the behavior change.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Cli/ShardCommandTest.php`
   - Expected: new zero-discovered-files tests pass (discovery branch and explicit-paths branch each assert exit 2 + `no test files discovered` on stderr + empty stdout), and the pre-existing "returns 3 and prints nothing when the shard is empty" test passes unmodified — proving the original bug path (zero files → exit 3) no longer occurs while the legitimate exit-3 path survives.
2. **test** `./vendor/bin/pest tests/Integration/Cli/WarpBinTest.php`
   - Expected: a new bin-level test asserts exit 2 for a fixture project whose phpunit.xml points at an existing-but-empty tests directory; "exits 3 for an empty shard" (line 212) and the sh -e guard-contract test (line 279) pass unmodified — the guard recipe now hard-fails (exit 2) instead of skipping when discovery finds nothing.
3. **runtime** In a temp fixture project containing a phpunit.xml whose testsuite directory exists but holds no test files, run `bin/warp shard 1/2`; then run the README's documented sh -e guard snippet against the same fixture.
   - Expected: direct invocation exits 2 with `[warp] no test files discovered - nothing to shard` on stderr and nothing on stdout; the guard snippet exits 2 (job fails) instead of printing `skip` — the CLI → CI-guard handoff no longer converts empty discovery into a green job.
4. **test** `./vendor/bin/pest`
   - Expected: full suite passes, 100%.

## Post-merge validation

- [ ] None — all checks are executable in the worker's worktree.
