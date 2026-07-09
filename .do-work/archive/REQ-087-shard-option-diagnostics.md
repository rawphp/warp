# REQ-087: Shard warns for ignored options

**UR:** UR-015
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Closure proof:** checkpoint_log:passed checkpoints:2 commit:46ee0d2
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

- [x] With no positional paths and phpunit.xml discovery active, passing `--suffix=Spec.php` emits a stderr warning that the suffix option is ignored by config discovery.
- [x] With explicit positional paths, passing `--configuration=custom.xml` emits a stderr warning that configuration discovery is bypassed by explicit paths.
- [x] Warnings do not change successful shard exit codes when the resulting discovered files are otherwise valid.
- [x] Stdout contains only shard file paths; warning text never appears in stdout.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="ShardCommand"`
   - Expected: shard command tests pass, including ignored-option warnings on stderr and clean stdout file lists.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green; existing option parsing and unknown-option errors are unchanged.

## Outputs

- src/Cli/ShardCommand.php — Emits stderr warnings when --suffix is ignored by phpunit.xml discovery or --configuration is ignored by explicit paths.
- tests/Unit/Cli/ShardCommandTest.php — Adds regression coverage for ignored shard option diagnostics while preserving clean stdout and exit code behavior.
