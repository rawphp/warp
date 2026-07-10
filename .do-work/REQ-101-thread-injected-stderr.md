# REQ-101: Thread injected stderr through TimingStore warnings

**UR:** UR-016
**Status:** backlog
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Size:** M
**Files:** src/Timing/TimingStore.php, src/Support/Stderr.php, src/Cli/ShardCommand.php, src/Cli/MergeCommand.php, src/Cli/TimingsCommand.php, src/Cli/WarpCli.php, tests/Unit/Cli/MergeCommandTest.php, tests/Unit/Timing/TimingStoreTest.php
**Depends on:**

## Task

`TimingStore` emits its warnings via `Stderr::write` (raw process STDERR — TimingStore.php:77, 126, 178, 188, plus any warnings added by REQ-095/096) while `WarpCli::run` and every command accept injected `$stdout`/`$stderr` stream resources. Thread the injected stream through: give TimingStore a warning sink (an injected stream resource or a `callable(string): void`, defaulting to process STDERR for the extension path), and have the CLI commands pass their `$stderr` through when constructing/using the store. The PHPUnit-extension usage keeps the process-STDERR default — only embedded/CLI callers change observably.

## Context

Finding 10 (UR-016), verified CONFIRMED: an embedded caller invoking `WarpCli::run` with `php://memory` streams gets store warnings leaked onto the host process's real STDERR while its captured stream stays empty — the injected-stream contract is violated. The package's own MergeCommandTest.php:73-104 already has to shell out via proc_open to observe these messages while sibling tests read `php://memory`; after this REQ those subprocess tests can assert against the injected stream directly. This was also independently flagged as a round-1 cleanup finding. Overlaps TimingStore with REQ-095/096/099 — footprint arbitration serializes; this REQ is numbered last so the warning-channel refactor lands on the final warning set.

## Acceptance Criteria

- [ ] `WarpCli::run` invoked with `php://memory` streams captures every store warning (merge junk-batch warnings, unlink warnings, load warnings) in the injected stderr stream; nothing reaches the raw process STDERR from the CLI path
- [ ] The PHPUnit extension path (no CLI, no injected streams) still emits warnings to process STDERR — recording-time behavior unchanged
- [ ] MergeCommandTest's proc_open-based warning assertions are converted to in-process injected-stream assertions (subprocess spawning retained only where a real process boundary is the thing under test)
- [ ] No remaining `Stderr::write` call sites inside TimingStore code paths reachable from CLI commands (grep confirms)

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce the original bug first: in-process `MergeCommand::run` with `php://memory` streams over a fixture containing an undecodable pending batch — assert the warning text appears in the captured stream (must fail pre-fix, where the stream is empty)
   - Expected: '[warp] skipped undecodable pending timings batch' present in the injected stream
2. **test** `./vendor/bin/pest --filter=MergeCommandTest && ./vendor/bin/pest --filter=TimingStoreTest`
   - Expected: all green
3. **test** `./vendor/bin/pest`
   - Expected: full suite green (constructor threading ripples through integration tests)
