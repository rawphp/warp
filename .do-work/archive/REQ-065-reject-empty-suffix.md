# REQ-065: Reject empty --suffix in shard discovery


**UR:** UR-012
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed commit:4cc3f55
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Cli/ShardCommand.php, src/Shard/TestFileFinder.php, tests/Unit/Cli/ShardCommandTest.php, tests/Unit/Shard/TestFileFinderTest.php
**Depends on:**

## Task

`ShardCommand` parses `--suffix=` into an empty string (`substr($arg, strlen('--suffix='))`) with no validation, and `TestFileFinder::find()` matches with `str_ends_with($file->getFilename(), $suffix)`. `str_ends_with($name, '')` is always `true`, so an empty suffix collects every file in the tree (READMEs, JSON fixtures, snapshots), not just test files. Reject an empty suffix: `ShardCommand` should emit an error and return a non-zero exit code when `--suffix=` yields an empty string. Add a defensive guard in `TestFileFinder::find()` as well so an empty suffix cannot silently match everything if reached programmatically.

## Context

Code-review finding #5 (CONFIRMED). The default suffix is the non-empty `'Test.php'`, so the collapse is only reachable via an explicit `--suffix=`. Handing non-test files to the runner causes errors and distorts duration weighting. Validate at the CLI boundary (clear user-facing error) and guard the finder (defence in depth).

## Acceptance Criteria

- [x] `warp shard 1/2 tests --suffix=` (empty suffix) exits non-zero and writes a clear error to stderr (e.g. `[warp] --suffix must not be empty`) instead of collecting every file.
- [x] `TestFileFinder::find()` rejects or is guarded against an empty `$suffix` so it cannot return non-matching files; a non-empty default (`Test.php`) and explicit non-empty suffixes behave exactly as before.
- [x] Existing shard discovery with the default suffix and with a valid explicit suffix is unchanged (regression-covered by existing tests).

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="ShardCommand|TestFileFinder"` — Expected: all pass, including new cases asserting empty-suffix is rejected (CLI non-zero exit + finder guard) and valid suffixes still resolve the expected files.
2. **runtime** Run `php bin/warp shard 1/2 tests --suffix=` in a scratch fixture tree containing a non-test file. Expected: non-zero exit, stderr error about empty suffix, and no non-test files emitted on stdout.

## Outputs

- src/Cli/ShardCommand.php — Rejects --suffix= with a clear stderr error and non-zero exit.
- src/Shard/TestFileFinder.php — Throws when called programmatically with an empty suffix.
- tests/Unit/Cli/ShardCommandTest.php — Covers CLI empty-suffix rejection while preserving valid suffix behavior.
- tests/Unit/Shard/TestFileFinderTest.php — Covers TestFileFinder empty-suffix guard.
