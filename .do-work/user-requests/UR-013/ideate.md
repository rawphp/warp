# Ideate — UR-013

**Reviewed:** 2026-07-09

## Explorer — Assumptions & Perspectives

- **Finding #2 is not a plain bug — it is the deliberate fix from REQ-069 (UR-012).** REQ-069's output line reads: "Normal shutdown backstop flushes are complete unless PHP is shutting down after a fatal error." That was accepted precisely so paratest workers (which never see `ExecutionFinished`) still prune stale test IDs. Reverting to `complete=false` on shutdown reintroduces REQ-069's accumulation bug. Any fix must satisfy BOTH: paratest worker natural-end flushes stay complete, `exit()`/`die()` mid-run flushes become incomplete. A discriminator exists: `TimingCollector::$startedAt` — an `exit()` mid-test leaves an in-flight entry, a natural worker end leaves it empty. The brief's finding #2 doesn't mention this constraint; capture must.
- **Finding #1 (filtered run wipes siblings) may be partially or fully unfixable at merge time** — the store cannot distinguish "testB was deleted" from "testB was filtered out"; pruning stale IDs (REQ-050/REQ-069 guarantee) and preserving filtered-out siblings are the same signal viewed from two runs. The right altitude is at the source: `TimingExtension::bootstrap` receives the PHPUnit `Configuration`, which exposes whether a filter/group/testsuite restriction was applied — a restricted run should flush `complete=false` (observed IDs upsert, nothing supersedes). The brief assumes a merge-side fix; the workable fix is extension-side.
- The brief assumes all 10 findings should be fixed. #9 (allWeightsEqual dead path) and #10 (typed exception) are cleanups with zero behavior change; per UR-012 precedent they belong in a trailing refactor REQ, decoupled from the correctness chain.

## Challenger — Risks & Edge Cases

- **Fixes #1 and #2 both gate `complete=true` harder — together they can starve the prune path.** If a team always runs `pest --parallel` with a testsuite restriction (common: separate Unit/Integration CI jobs run `--testsuite=Unit`), every flush becomes incomplete and stale IDs never prune again — silently reintroducing the exact skew REQ-069 fixed. Capture needs an explicit acceptance criterion covering "restricted-but-complete-for-that-scope" semantics or a documented, decided trade-off.
- **Finding #8's fix (delete junk pending batches) must respect the UR-011 standing decision: "warp shard/timings are read-only; disk merge only via explicit `warp merge`."** Deleting undecodable batches inside `load()` would violate it (and race: `load()` holds no lock). The deletion belongs only in `mergeToDisk()` under the merge lock. Same constraint shapes #5: unlink-failure tolerance is a merge-path concern.
- **Finding #5's fix has an ordering trap.** If merge switches to "warn and continue" on unlink failure, the surviving already-merged batch gets re-applied on the next merge. That is only safe because `apply()` is idempotent for identical batches — but a *complete* batch re-applied AFTER newer batches would supersede newer data. The pending-file timestamp ordering makes this safe today (old batch sorts first); a fix must not break that ordering assumption, and a test should pin it.
- **Finding #4 is the residual half of REQ-068** — that REQ fixed the short-write guard in `writePending()` only; `mergeToDisk()` still has the drifted copy. Fixing #4 by extracting one shared atomic-write helper (as the brief suggests) closes the drift class, not just the instance — but the helper then sits in TimingStore's footprint alongside #1/#5/#8 fixes, so the TimingStore REQs must be serialized (UR-011/UR-012 precedent).

## Connector — Links & Reuse

- Reconciliation reading list for capture: REQ-050 (batch completeness flag), REQ-069 (completeness under paratest) for #1/#2; REQ-068 (short-write in writePending) for #4; REQ-048 (atomic pending writes) for #4/#8; REQ-051 (read-only load) for #5/#8.
- The atomic tmp+rename+verify sequence wanted by #4 already exists in guarded form in `writePending()` (`src/Timing/TimingStore.php:47`) — extract to `Support/` (e.g. `AtomicFile::write`) rather than writing a third copy; `Support/FileLock.php` is the pattern precedent.
- #7 (`--timings-dir=` empty) mirrors REQ-065 (reject empty `--suffix`) — same guard shape, same test shape, in `TimingStoreArgumentParser`. #6 (bench canonicalization) can reuse `ShardCommand::canonicalFiles`'s `Paths::canonical` loop — consider exposing it (`Paths` or sharder) instead of duplicating in the bench script (REQ-070 consolidation precedent).
- UR-012 decision precedent applies directly: group by subsystem/concern, not 1:1 finding→REQ; serialize TimingStore-touching REQs via hard deps; cleanups last.

## Summary

Two findings (#1, #2) sit in direct tension with guarantees accepted in REQ-050/REQ-069 — the completeness flag is one bit carrying three meanings (crash?, filtered?, whole-suite?), and naive fixes trade one data-loss bug for another. Capture must state the reconciled semantics explicitly (in-flight-test discriminator for #2, restriction-aware flush for #1) and add a criterion that stale-ID pruning still works under always-parallel, always-restricted CI. Everything TimingStore-touching (#1, #4, #5, #8) needs the usual serialized dependency chain; #6/#7 are independent small guards; #9/#10 fold into one trailing cleanup REQ.
