# REQ-087: Shard warns for ignored options

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.82488
**Claimed at:** 2026-07-09T20:51:46Z
**Heartbeat:** 2026-07-09T20:51:46Z
<!-- claimed-end -->
**UR:** UR-015
**Status:** in-progress
**Created:** 2026-07-09
**Layer:** none
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Cli/ShardCommand.php, tests/Unit/Cli/ShardCommandTest.php
**Depends on:**

## Task

Emit explicit diagnostics when `warp shard` receives options that are parsed but ignored because of the selected discovery mode.

## Context

Confirmed finding 7: `--suffix=` is silently ignored when phpunit.xml discovery is used, and `--configuration=` is silently ignored when explicit paths are provided. Clarification: diagnostics for ignored shard options go to stderr so stdout remains the machine-readable shard-file list.

## Acceptance Criteria

- [ ] With no positional paths and phpunit.xml discovery active, passing `--suffix=Spec.php` emits a stderr warning that the suffix option is ignored by config discovery.
- [ ] With explicit positional paths, passing `--configuration=custom.xml` emits a stderr warning that configuration discovery is bypassed by explicit paths.
- [ ] Warnings do not change successful shard exit codes when the resulting discovered files are otherwise valid.
- [ ] Stdout contains only shard file paths; warning text never appears in stdout.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="ShardCommand"`
   - Expected: shard command tests pass, including ignored-option warnings on stderr and clean stdout file lists.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green; existing option parsing and unknown-option errors are unchanged.
