# Ideate — UR-011

**Reviewed:** 2026-07-09

## Explorer — Assumptions & Perspectives

- The brief is a review-findings dump, not a prioritized ask. It assumes all 10 findings should be fixed, but the trailing paragraph also names 9 over-cap findings ("most notably" five of them) without saying whether they are in scope. Concrete problem: capture either silently drops the Pest `$__filename` fragility (a real silent-data-loss risk for a Pest-first tool) or silently doubles the scope. Triggered by the brief's closing paragraph.
- Fixing finding #2 (README exit-code guard) and #3 (destructive read path) changes the CLI's public exit-code/read-write contract that REQ-045 just documented. Any CI pipeline already built from the current README recipe is a stakeholder; the S3 plan doc (`docs/superpowers/plans/2026-07-08-s3-timing-capture-sharding.md`) is the authoritative source per decisions.md and would go stale. Triggered by findings #2, #3, #10.
- Finding #1's fix (canonical path keys) is a stored-data-format decision, not just a lookup fix: existing `timings.json` artifacts keyed under the old convention become unmatchable after the fix. The brief doesn't say whether recorded data may be invalidated. Per decisions.md 2026-07-05 ("zero public users pre-release") a clean break is likely acceptable, but it should be an explicit decision, not an accident. Triggered by finding #1.
- Finding #9 (read phpunit.xml testsuites instead of hardcoded `tests`/`Test.php`) is feature-sized — new configuration parsing, new failure modes, its own tests — not a bug fix. Bundling it with the one-line fixes distorts sizing and delays the safety-critical fixes. Triggered by finding #9.

## Challenger — Risks & Edge Cases

- Findings #3, #5, #7, #8 all live inside `TimingStore`'s merge machinery (lock lifecycle, batch ordering, write atomicity, glob discovery). Fixing them as four independent REQs invites conflicting patches to the same ~50 lines and four rounds of churn on the same tests. Concrete scenario: #3's fix (make the shard read path non-destructive / merge-on-write) can eliminate the very code paths #5 and #7 patch. Decompose as one merge-machinery REQ (or a tight dependency chain), not four peers.
- Path canonicalization (#1) breaks cross-machine shard agreement during any window where CI nodes run mixed warp versions — old nodes key `tests/...`, new nodes canonicalize differently, and plans diverge (the exact failure the fix targets). Pre-release this is tolerable, but the REQ should state that all shard nodes must run the same warp version, and the README should say so. Triggered by finding #1.
- Changing the pending-batch filename to embed a timestamp (#5's suggested fix) silently strands already-written old-format pending files unless mergePending still globs them. Clean break is fine pre-release, but the REQ must decide: handle both formats or document that pending/ must be emptied on upgrade. Triggered by finding #5.
- The README exit-code fix (#2) is subtle shell: `FILES=$(...)` inside `if` suppresses `set -e`, and the corrected `rc=$?` pattern must be verified in a real `sh -e` run, not eyeballed — an incorrect recipe here recreates the exact silent-skip failure the fix targets. A runtime verification step exercising exit codes 0, 2, and 3 is mandatory. Triggered by finding #2.
- do-work footprint discipline: `TimingStore.php` is touched by four findings, `ShardCommand.php` by three. Parallel workers on file-overlapping REQs will merge-conflict. Precedent: decisions.md 2026-07-07 (footprint check serializes phpunit.xml). Depends-on chains must serialize by file footprint.

## Connector — Links & Reuse

- Finding #6 (CLI ignores `WARP_TIMINGS_DIR`) and over-cap finding C2 (`WarpMode` env-flag idiom triplicated) are both "centralize env handling" — one REQ can route both CLI defaults through `TimingStore::fromEnv()` and collapse the WarpMode duplication, touching the same call sites once.
- `SnapshotStore::withLock()` (src/Db/SnapshotStore.php:30-48) already encapsulates the exact lock choreography `TimingStore::mergePending()` re-implements (over-cap finding C3). The merge-machinery fix (#3/#7) should extract a shared lock helper rather than leave a second (or write a third) copy.
- If the sharder exposes its resolved weights/plan as a public API to support canonical-key lookup (#1), the bench script's duplicated fallback-weight policy (over-cap finding A4) can consume the same API — fixing the S3-gate-measures-a-copy problem for free. Coordinate these two rather than fixing separately.
- Test patterns to extend already exist: `tests/Unit/Timing/TimingStoreTest.php` (116 lines) and `tests/Unit/Shard/*` cover the exact classes under repair; `tests/Integration/Cli/WarpBinTest.php` exercises exit codes end-to-end and is the right home for the exit-code-contract regression tests.
- decisions.md 2026-07-05: "zero public users pre-release, clean break" — no migration/back-compat REQs are needed for storage-format or exit-code changes; record the clean-break choice per finding instead.

## Summary

Decompose by subsystem, not by finding: one merge-machinery REQ (findings #3, #5, #7, #8 + reuse of `withLock`), one path-canonicalization REQ (#1, coordinated with the sharder-weights API the bench needs), one env/dir-resolution REQ (#6 + WarpMode dedup), one CLI-error-contract REQ (#10), and one docs/contract REQ (#2 + plan-doc updates). Before capture, two scope questions need answers: are the 9 over-cap findings in scope (especially the Pest `$__filename` fragility), and does feature-sized #9 (phpunit.xml testsuites) ship in this UR or get deferred to its own?
