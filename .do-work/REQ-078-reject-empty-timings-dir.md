# REQ-078: Reject empty --timings-dir= at parse time

**UR:** UR-013
**Status:** backlog
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Cli/TimingStoreArgumentParser.php, tests/Unit/Cli/ShardCommandTest.php, tests/Unit/Cli/TimingsCommandTest.php
**Depends on:**

## Task

`TimingStoreArgumentParser::parse` builds `new TimingStore($dir)` from `--timings-dir=` without an empty-value guard (src/Cli/TimingStoreArgumentParser.php:29), bypassing `TimingStore::fromEnv()`'s deliberate empty-string fallback. `warp shard 1/2 --timings-dir=` (e.g. an unset CI variable expanding to nothing) yields `dir=''` → the store probes `is_dir('/pending')` / `is_file('/timings.json')` at the filesystem root, and write paths would `Dirs::ensure('/pending')` — while the user silently gets count-balanced sharding instead of an error. Throw `InvalidArgumentException` (`[warp] --timings-dir must not be empty`) when the value is empty, mirroring REQ-065's empty `--suffix` rejection (decided at question gate).

## Context

Code-review finding #7. REQ-065 (archived) is the exact guard-shape and test-shape precedent — same parser layer, same exception type, same exit-2 stderr surfacing through the existing command catch blocks. All three commands (merge/shard/timings) route through this parser, so one guard covers them.

## Acceptance Criteria

- [ ] `--timings-dir=` with an empty value throws `InvalidArgumentException` with a `[warp]`-prefixed message; the command exits 2 with the message on stderr (reproduces then fixes the finding-#7 scenario — no filesystem probe at `/`).
- [ ] A non-empty `--timings-dir=DIR` still constructs the store for DIR exactly as before (existing parser tests pass).

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="ShardCommand|TimingsCommand|MergeCommand"` — Expected: all pass, including a new empty `--timings-dir=` rejection case asserting exit code 2 and the stderr message.
2. **test** `./vendor/bin/pest` — Expected: full suite green.
