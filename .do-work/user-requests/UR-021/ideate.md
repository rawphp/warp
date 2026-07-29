# Ideate — UR-021

**Reviewed:** 2026-07-29

## Explorer — Assumptions & Perspectives

- **“Behavior-preserving” is underspecified for race policy, not just public API.** Package consumers care that ENOENT mid-walk stays success, not-empty retries stay bounded, and non-ENOENT still throws `[warp]`. Scenario: a refactor that collapses `unlinkPath`/`tryRmdir` accidentally treats permission denied as success and green tests still pass while real FS bugs go silent. Triggered by the brief’s opening “behavior-preserving” claim without an explicit failure-model checklist.
- **Dropping public `$beforeFsOp` without a replacement test seam may delete the only reliable unit race simulation.** Unit tests for mid-walk vanish and sticky not-empty currently inject via that hook. Scenario: hook removed, race tests deleted or weakened to happy-path only, and a future regression reintroduces bare `unlink` warnings under Laravel’s ErrorException handler. Triggered by item 1 (“drop public `$beforeFsOp` or demote…”).
- **Settle ownership choice is still open and changes who pays latency.** Folding settle into `MysqldServer::stop()` affects golden build stop paths and any future stop callers; deleting settle relies entirely on resilient delete + backoff. Scenario: fold into stop → every stop costs +100ms even when the datadir is kept (golden success path); delete settle → residual InnoDB churn relies only on retry/backoff under parallel Pest. Triggered by item 3’s either/or without a preferred default.

## Challenger — Risks & Edge Cases

- **Not-empty backoff without a deadline can stretch recycle cost under pathological stickiness.** Five attempts with sleep between them multiplies wall time on every recycle if a tree stays non-empty until the last try. Scenario: flaky host FS or a live sibling still writing → worker recycle adds tens of ms × attempts × nested dirs and slows the suite. Triggered by item 2 (“small backoff between not-empty attempts”).
- **Demoting the hook to “internal” still leaves a mutable static if it remains public-ish.** A package-visible static is still API surface for anyone who can call it. Scenario: a host test sets the collaborator and forgets to clear it → later tests in the same process get wrong delete behavior or masked failures. Triggered by item 1’s “demote to internal test-only collaborator” alternative.
- **Removing `settleAfterStop` while leaving tests that assert a 100ms sleep will fail the suite until tests move with the design.** The teardown test currently reflects into private `settleAfterStop` and asserts duration. Scenario: method deleted, test still present → red suite for the wrong reason; or sleep moved into `stop()` without updating reflection tests → false confidence. Triggered by items 3 and 5 together.
- **Keeping the live-mysqld sweep guard is necessary but not a free no-op during refactor.** Touching `SnapshotDatabaseManager` for settle cleanup can accidentally regress the alive-after-TERM continue. Scenario: settle deletion rewrite re-indents `sweepDeadWorkers` and drops the second `alive()` check → live datadir deleted under TERM-ignoring mysqld again. Triggered by item 4 (“keep … guard and its tests”).

## Connector — Links & Reuse

- **Direct follow-on to UR-020 / REQ-114–116**, which landed race-safe `Dirs::delete`, `settleAfterStop`, and the sweep live-pid guard. This UR should simplify that implementation, not re-litigate the failure model (ENOENT success, bounded not-empty retry, non-ENOENT throw). Scenario: capture reopens “should we ignore all unlink errors?” and undoes UR-020 acceptance. Triggered by the whole brief as a code-review restructure of that work.
- **`FileLock` already captures PHP warnings via `set_error_handler`** (`src/Support/FileLock.php`). Collapsing `unlink`/`rmdir` into one `removeNode` is the local judo; optionally sharing a tiny capture helper with FileLock is secondary reuse. Scenario: two more copy-pasted error-handler blocks land if `removeNode` is half-extracted. Triggered by item 1’s single-pipeline goal.
- **Call sites of `Dirs::delete` and `MysqldServer::stop` are concentrated** (`SnapshotDatabaseManager`, `GoldenSnapshotBuilder`, `CopyOnWriteCloner`, `SnapshotStore`). Settle-in-`stop()` has a small fan-out; delete-only settle can leave golden exception-path stop→delete relying solely on resilient delete (already true today for that path). Triggered by item 3.

## Summary

UR-021 is a behavior-preserving structure pass on the UR-020 teardown race fix: one FS remove pipeline, no package-surface test hook, self-healing not-empty retries with backoff, and no usleep-only settle abstraction—while keeping the live-mysqld sweep guard. Before decomposing, lock the settle decision (fold into `stop()` vs delete and rely on delete+backoff), and require race unit tests to survive without a public static seam so the failure model stays testable.

## Lower confidence

- Whether macOS vs Linux needs different not-empty backoff constants.
- Whether a private package-internal test collaborator is worth more complexity than pure process/FS-level race tests.
