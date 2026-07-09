# REQ-085: Shard canonicalization follows config root

**UR:** UR-015
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Closure proof:** checkpoint_log:passed commit:ef23791
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** M
**Files:** src/Cli/ShardCommand.php, src/Shard/SuiteDiscovery.php, src/Support/Paths.php, tests/Unit/Cli/ShardCommandTest.php, tests/Unit/Timing/TestFileResolverTest.php
**Depends on:**

## Task

Fix `warp shard` path canonicalization for explicit `--configuration=` files outside the current working directory and for symlinked test directories whose realpath is outside the command root.

## Context

Confirmed finding 5: `canonicalFiles()` canonicalizes discovered files relative to `getcwd()`, so config-driven suites from another directory and symlinked external test directories hard-fail as "outside project root." Clarification: when `--configuration` is provided, treat the configuration directory as the shard root. Discovered files under that root use root-relative keys; symlink targets outside the root are allowed with stable absolute realpaths.

## Acceptance Criteria

- [x] Running `warp shard` from outside the app with `--configuration=/path/to/app/phpunit.xml` succeeds and emits file paths relative to the configuration directory for files under that app.
- [x] A phpunit suite that includes a symlinked directory outside the configuration root succeeds and emits stable absolute realpaths for those outside-root files instead of exiting 2.
- [x] Timing-key matching remains consistent: discovered root-relative paths still match root-relative stored timings, while outside-root symlink targets use the same absolute form across discovery and timing lookup.
- [x] Existing relative, dot-relative, and absolute path canonicalization tests for normal in-root paths continue to pass.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="ShardCommand|TestFileResolver"`
   - Expected: CLI/path tests pass, including config-root discovery from another cwd and symlinked outside-root suite coverage.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green; normal root-relative timing keys and shard output remain stable.

## Outputs

- src/Cli/ShardCommand.php — Uses suite/config root for suite-discovered shard canonicalization and allows outside-root realpaths.
- src/Support/Paths.php — Adds optional outside-root canonicalization returning stable absolute realpaths.
- src/Timing/TestFileResolver.php — Uses outside-root canonicalization so timing keys match symlinked external suite files.
- tests/Unit/Cli/ShardCommandTest.php — Covers config-root discovery from another cwd and symlinked outside-root suite output.
- tests/Unit/Timing/TestFileResolverTest.php — Covers timing resolver absolute realpaths for existing outside-root files.
