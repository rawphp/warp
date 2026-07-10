# REQ-097: Adopt PHPUnit's file iterator in TestFileFinder

**UR:** UR-016
**Status:** done
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed commit:b633465
**Criteria approved:** agent-drafted
**Size:** M
**Files:** src/Shard/TestFileFinder.php, src/Cli/ShardCommand.php, composer.json, composer.lock, tests/Unit/Shard/TestFileFinderTest.php, tests/Unit/Cli/ShardCommandTest.php
**Depends on:**

## Task

Replace `TestFileFinder`'s hand-rolled `RecursiveDirectoryIterator` walk with `sebastian/file-iterator` (PHPUnit's own walker — already installed as a transitive dependency; add it as an explicit `require` so the direct usage is declared). This fixes three confirmed divergences from what phpunit itself runs, by construction:

1. **Symlinks (finding 11):** PHPUnit's iterator passes FOLLOW_SYMLINKS; the current walker never descends symlinked directories, so those test files land in NO shard and silently never run.
2. **Suffixes (finding 13):** PHPUnit's default suffixes for path arguments are `['Test.php', '.phpt']` (vendor Merger.php:866); the current single `'Test.php'` suffix silently drops `.phpt` tests from every shard. Default to both suffixes; `--suffix` continues to override.
3. **Hidden dirs (finding 19):** PHPUnit's iterator rejects any path containing a `/.segment/`; the current walker collects files under dot-directories (`.history/`, `.cache/`), which then reach phpunit as explicit file args (bypassing its own filter) and cause redeclaration fatals or stale-test runs.

Deterministic ordering across machines must be preserved (the existing sort stays — file-iterator's raw order is not guaranteed stable across filesystems).

## Context

Findings 11, 13, 19 (UR-016), all verified CONFIRMED empirically against PHPUnit's Facade — the connector observation held: all three are the same defect class ("hand-rolled discovery diverges from PHPUnit's"), and adopting `sebastian/file-iterator` keeps parity automatically as PHPUnit evolves. Question-gate decision: adopt the iterator rather than patching the walker. Silent-test-loss class — the worst failure mode a sharder can have (CI stays green while tests never run anywhere).

## Acceptance Criteria

- [x] A fixture tree with a symlinked test directory yields the symlinked test files in `TestFileFinder::find` output, matching PHPUnit's `sebastian/file-iterator` result for the same tree
- [x] `.phpt` files are discovered by default alongside `*Test.php`; an explicit `--suffix=` value still narrows discovery to that suffix
- [x] Files under dot-directories (e.g. `tests/.cache/StaleTest.php`) are excluded from discovery
- [x] Output remains sorted and deterministic across repeated invocations
- [x] `composer.json` declares `sebastian/file-iterator` explicitly; `composer validate` passes
- [x] Union-of-shards coverage over a fixture tree containing a symlink, a `.phpt` file, and a hidden-dir decoy equals the hardcoded expected file list (independently constructed, not derived from TestFileFinder)

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce the original bugs first: fixture tree with `tests/Linked -> ../shared` symlink, a `tests/Feature/example.phpt`, and `tests/.cache/StaleTest.php`; assert find() returns the symlinked + .phpt files and excludes the hidden-dir file against a hardcoded expected list (must fail pre-fix on all three counts)
   - Expected: exact match with the independently hardcoded list
2. **test** `./vendor/bin/pest --filter=TestFileFinderTest && ./vendor/bin/pest --filter=ShardCommandTest`
   - Expected: all green
3. **build** `composer validate && composer install --dry-run`
   - Expected: valid manifest; no dependency conflicts from the explicit require

## Outputs

- src/Shard/TestFileFinder.php — php-file-iterator Iterator+ExcludeIterator with FOLLOW_SYMLINKS, hidden-dir exclusion, multi-suffix (given-path-form output contract preserved)
- src/Cli/ShardCommand.php — Default suffix ['Test.php', '.phpt']; --suffix= still narrows
- composer.json — Explicit require phpunit/php-file-iterator ^6.0.1 (current published name of the package cited as sebastian/file-iterator)
- composer.lock — Regenerated to record the explicit require (no version change)
- tests/Unit/Shard/TestFileFinderTest.php — Red-then-green fixture tests + PHPUnit Facade parity test
- tests/Unit/Cli/ShardCommandTest.php — Union-of-shards coverage test with symlink/.phpt/hidden-dir decoy

Review note (advisory): README fallback-discovery text and ShardCommand's no-config stderr message still describe Test.php-only default discovery; now stale since .phpt is discovered by default.
