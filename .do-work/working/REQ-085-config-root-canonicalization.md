# REQ-085: Shard canonicalization follows config root

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.82488
**Claimed at:** 2026-07-09T20:37:07Z
**Heartbeat:** 2026-07-09T20:37:07Z
<!-- claimed-end -->
**UR:** UR-015
**Status:** in-progress
**Created:** 2026-07-09
**Layer:** none
**Closure proof:**
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

- [ ] Running `warp shard` from outside the app with `--configuration=/path/to/app/phpunit.xml` succeeds and emits file paths relative to the configuration directory for files under that app.
- [ ] A phpunit suite that includes a symlinked directory outside the configuration root succeeds and emits stable absolute realpaths for those outside-root files instead of exiting 2.
- [ ] Timing-key matching remains consistent: discovered root-relative paths still match root-relative stored timings, while outside-root symlink targets use the same absolute form across discovery and timing lookup.
- [ ] Existing relative, dot-relative, and absolute path canonicalization tests for normal in-root paths continue to pass.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="ShardCommand|TestFileResolver"`
   - Expected: CLI/path tests pass, including config-root discovery from another cwd and symlinked outside-root suite coverage.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green; normal root-relative timing keys and shard output remain stable.
