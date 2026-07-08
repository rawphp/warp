# Ideate — UR-010

**Reviewed:** 2026-07-09

## Explorer — Assumptions & Perspectives

- The plan assumes every timing write can be merged after parallel Pest workers finish; a corrupt batch or overlapping rerun could leave stale file entries unless merge semantics are tested. This is triggered by the architecture note that workers write lock-free pending batches and merge deterministically.
- The plan assumes test file attribution is reliable for Pest and classic PHPUnit tests; if Pest's generated `__filename` path is missed, the artifact becomes unportable or polluted with eval paths. This is triggered by the spike fact that `TestMethod::file()` is wrong for Pest tests.
- The CI consumer depends on `warp shard` being shell-safe; if diagnostics leak to stdout or empty shards exit successfully, `pest $(warp shard ...)` can accidentally run the wrong files or the full suite. This is triggered by the global constraint that stdout must contain only file lists.

## Challenger — Risks & Edge Cases

- File-replacement semantics are subtle: a filtered rerun of one test file can erase the rest of that file's timings, so docs and tests need to make the full-run assumption explicit. This is triggered by the `TimingStore` requirement that a batch supersedes all previous entries for covered files.
- Deterministic sharding can still diverge across CI machines if file discovery order, unknown-file weights, or equal-load tie breaks vary. This is triggered by the requirement that every shard machine independently computes the same plan without coordination.
- Registering a not-yet-existing PHPUnit extension can break the entire test runner before implementation, so the TimingExtension REQ must keep the expected red/green sequence clear. This is triggered by Task 5's note that the parent Pest run refuses to boot until the extension class exists.

## Connector — Links & Reuse

- `WarpMode::timingsEnabled()` should mirror the existing strict env parsing in `src/WarpMode.php`, matching prior `WARP_MODE` and `WARP_DB` decisions instead of introducing case-insensitive parsing.
- The timing store can reuse `RawPHP\Warp\Db\Dirs`, which already exists from S2 and is used in tests for cleanup; this keeps filesystem behavior consistent with snapshot tooling.
- The plan follows the established UR-001 and UR-006 pattern where execution-ready plan tasks become 1:1 REQs and the plan remains the authoritative implementation source.

## Summary

The plan is execution-ready and should be captured as ten coordinated REQs rather than rewritten into a second implementation spec. The main risks are deterministic timing artifact behavior, Pest file attribution, and keeping the CLI safe for shell substitution in CI.
