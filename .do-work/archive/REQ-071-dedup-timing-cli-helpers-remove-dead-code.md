# REQ-071: De-duplicate timing/CLI helpers and remove dead code


**UR:** UR-012
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed all 3 verification checkpoints passed; commit:a717f31
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** L
**Files:** src/Timing/TimingStore.php, src/Timing/TimingExtension.php, src/Cli/MergeCommand.php, src/Cli/TimingsCommand.php, src/Cli/ShardCommand.php, src/Cli/TimingStoreArgumentParser.php, src/Support/Stderr.php, tests/Unit/Cli/MergeCommandTest.php, tests/Unit/Cli/ShardCommandTest.php, tests/Unit/Cli/TimingsCommandTest.php, tests/Unit/Timing/TimingStoreTest.php
**Depends on:** REQ-065, REQ-069

## Task

A single subsystem cleanup pass removing duplication and dead code surfaced by the review (findings #9 dedup-half and #10). Four concrete changes:

1. **Extract the duplicated `warn()` helper.** The private static `warn()` (write to `STDERR` if defined, else `php://stderr`) is copy-pasted verbatim in `TimingStore` and `TimingExtension`. Extract one shared helper in the `Support` namespace (e.g. `Support\Stderr::write()`) and call it from both.
2. **Extract the duplicated `--timings-dir` arg parser.** `MergeCommand` and `TimingsCommand` share a byte-identical parse loop (build `TimingStore`, reject `--unknown`, reject bare args); `ShardCommand` re-derives the same `--timings-dir` handling. Extract one shared parser both/all delegate to.
3. **Remove dead `TimingStore::mergePending()`** — it has zero callers (verified by grep across src/bin/bench/tests) and only wraps `mergeToDisk()` discarding its return.
4. **Remove the double `phpunit.xml` resolution in `ShardCommand`** — `configurationPath()` is called at `ShardCommand` and again inside `SuiteDiscovery::discover()`; resolve once (e.g. `discover()` returns/accepts the resolved path, or a `discoverOrNull()` variant) so the filesystem is not scanned twice per invocation.

## Context

Code-review findings #9 (warn + arg-parse duplication) and #10 (dead `mergePending`, double phpunit scan). Grouped into one refactor REQ per the UR-012 decomposition decision (recorded in `.do-work/decisions.md`) — all are pure-duplication/dead-code cleanup in the timing/CLI subsystem, kept together as one altitude pass and ordered LAST so it runs after the behaviour fixes (depends on REQ-065 for `ShardCommand`, REQ-069 for `TimingStore`/`TimingExtension`). No behaviour change intended.

## Acceptance Criteria

- [x] `warn()` exists in exactly one place (a `Support` helper); `TimingStore` and `TimingExtension` call it — no duplicated copies remain (verify by grep).
- [x] `--timings-dir` / unknown-option / bare-arg parsing exists in one shared parser used by `MergeCommand`, `TimingsCommand`, and `ShardCommand`; the three commands behave identically to before for valid and invalid args (existing command tests pass unchanged).
- [x] `TimingStore::mergePending()` is removed and `grep -rn 'mergePending' src bin bench tests` returns no callers.
- [x] `phpunit.xml` is resolved once per `warp shard` invocation (no second `configurationPath()`/discovery scan); shard output for the no-paths default case is unchanged.
- [x] Full test suite green — this is a behaviour-preserving refactor.

## Verification Steps

> Execute these after implementation to confirm the refactor is behaviour-preserving. Each must pass before committing.

1. **test** `./vendor/bin/pest` — Expected: full suite green; Merge/Timings/Shard command tests and TimingStore tests pass with identical observable behaviour.
2. **runtime** `grep -rn 'mergePending' src bin bench tests` — Expected: zero matches (dead code removed).
3. **runtime** `grep -rn 'function warn' src` — Expected: the `warn`/stderr helper is defined in a single Support file, not duplicated in `TimingStore` and `TimingExtension`.

## Outputs

- src/Cli/MergeCommand.php — Delegates strict timing-store CLI argument parsing to the shared parser.
- src/Cli/ShardCommand.php — Delegates --timings-dir parsing, preserves shard-specific parsing, and avoids phpunit.xml pre-scan.
- src/Cli/TimingStoreArgumentParser.php — New shared parser for --timings-dir, unknown option, and unknown argument handling.
- src/Cli/TimingsCommand.php — Delegates strict timing-store CLI argument parsing to the shared parser.
- src/Support/Stderr.php — New centralized stderr writer helper.
- src/Timing/TimingExtension.php — Uses Support\\Stderr instead of a private warn helper.
- src/Timing/TimingStore.php — Uses Support\\Stderr and removes dead mergePending wrapper.
- tests/Unit/Cli/MergeCommandTest.php — Adds structural coverage for single shared timing-dir parser.
- tests/Unit/Cli/ShardCommandTest.php — Adds coverage preventing shard-side phpunit.xml pre-scan.
- tests/Unit/Timing/TimingStoreTest.php — Adds coverage for removed merge wrapper and centralized stderr helper.
