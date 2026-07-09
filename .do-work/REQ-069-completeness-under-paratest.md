# REQ-069: Ensure batch completeness under paratest/parallel workers

**UR:** UR-012
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
**Files:** src/Timing/TimingExtension.php, src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php, tests/Integration/Timing/TimingCaptureTest.php
**Depends on:** REQ-068

## Task

`complete=true` is produced only by the `ExecutionFinished` subscriber in `TimingExtension`. Under paratest/`pest --parallel`, worker processes may never receive `ExecutionFinished` and flush via `register_shutdown_function(... flush(false))`, while the main process's `ExecutionFinished` fires with an empty collector. The `complete` flag is the only gate for `TimingStore::apply()`'s file-supersede logic that prunes stale test IDs for a fully-observed file — so under always-parallel runs, timings for deleted/renamed tests never get pruned and accumulate, skewing shard balance until a `VERSION` bump. Make a fully-observed file's timings supersede correctly under parallel execution.

## Context

Code-review finding #3 (CONFIRMED mechanism, realism caveat). Reconcile with UR-011/REQ-050 ("Batch completeness flag — partial crash-flush batches must not supersede complete data") FIRST: read `.do-work/archive/REQ-050-batch-completeness-flag.md` and confirm the intended completeness semantics before changing anything — the goal is to close the paratest gap without weakening REQ-050's guarantee that partial crash-flush batches must NOT supersede complete data. The documented workflow (`bench/shard-spread.sh`) is `pest --parallel`, so this is the primary path, not an edge. Investigate the fix at the right altitude (e.g. per-file completeness attribution from the worker that fully ran that file, rather than relying solely on the main process's `ExecutionFinished`) — do not special-case a magic self-heal that only works when a single-process run happens to occur.

## Acceptance Criteria

- [ ] Under a simulated parallel run (workers flush `complete=false`; main process `ExecutionFinished` with an empty collector), a file that was fully observed still has its stale prior test IDs superseded/pruned in `TimingStore::apply()`.
- [ ] REQ-050's guarantee is preserved: a genuinely partial crash-flush batch does NOT supersede complete data (verify against the archived REQ; existing REQ-050 tests still pass).
- [ ] A deleted/renamed test's stale `ms` no longer accumulates across repeated parallel runs (demonstrated by a test that runs the merge twice and asserts stale IDs are gone).
- [ ] The reconciliation with REQ-050 is noted in the commit body.

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="TimingStore|TimingCapture|TimingExtension"` — Expected: all pass, including a new parallel-completeness case asserting supersede fires for fully-observed files and does NOT fire for partial crash-flush batches.
2. **test** `./vendor/bin/pest` — Expected: full suite green (this REQ touches shared merge machinery; confirm no cross-module regression).
