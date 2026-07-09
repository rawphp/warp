# REQ-062: Path-unit — README documents the corrected CLI contract

<!-- claimed-start -->
**Claimed by:** codex-main
**Claimed at:** 2026-07-09T02:44:23Z
**Heartbeat:** 2026-07-09T02:44:23Z
<!-- claimed-end -->

**UR:** UR-011
**Status:** done
**Created:** 2026-07-09
**Layer:** docs
**Entry point:** A user follows README.md's "Timing-based sharding" / CI recipe sections to wire warp into a CI matrix.
**Terminal state:** Following the README verbatim produces a pipeline where shard errors fail the job (exit 2) while empty shards skip cleanly (exit 3), recording and sharding read the same directory, `warp merge` is in the workflow, and the canonical-key version-lock caveat is stated.
**Parent:**
**Closure proof:** checkpoint_log:passed all 3 verification checkpoints passed commit:86f95ee
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** M
**Files:** README.md
**Depends on:** REQ-052, REQ-055, REQ-058, REQ-060

## Task

Update README.md to match the contracts shipped by this UR (README only — the S3 plan doc stays as a dated historical record, per the UR-011 clarification and decisions.md 2026-07-05 precedent):

1. **CI recipe** (finding #2): replace the `if FILES=$(warp shard ...); then pest $FILES; fi` snippet (README.md:254-256) with an exit-code-aware pattern, e.g. capture output, then `rc=$?`; run pest on 0, skip on 3, `exit $rc` on anything else. Document the full exit-code table (0 = shard with files, 2 = error, 3 = empty shard) next to it, and note the corrected pattern is exercised by the integration suite (REQ-053).
2. **Merge step**: document that `warp shard`/`warp timings` are read-only and that `warp merge` is the explicit compaction step — recommend merging once after recording, before persisting the artifact; note read-only artifact restores are supported.
3. **Env parity** (finding #6): document that `WARP_TIMINGS_DIR` is honored by all subcommands, with `--timings-dir` as the explicit override.
4. **Canonical keys + version lock** (finding #1): note that timing keys are canonical root-relative paths, that any path spelling now yields identical plans, and that all CI nodes must run the same warp version (mixed versions across the key-format change compute divergent plans).
5. **Suite discovery** (finding #9): document phpunit.xml testsuite-driven discovery as the path-less default, the `--configuration` flag, and the no-phpunit.xml fallback.
6. **Clean break note**: recorded timings/pending artifacts from pre-UR-011 versions are invalidated; delete and re-record.

## Context

Review finding #2 is a docs bug with CI-green-zero-tests consequences: the recommended guard conflated error exit 2 with empty-shard exit 3, silently skipping a shard's entire test run on any misconfiguration. The rest of the section drifted from the contracts this UR establishes. README.md:249-251 currently documents only exit code 3.

## Acceptance Criteria

- [x] The old `if FILES=$(...warp shard...); then` pattern no longer appears anywhere in README.md (grep-verified); the replacement branches explicitly on exit 3 vs other non-zero.
- [x] The exit-code table documents 0, 2, and 3 with their CI-recipe consequences.
- [x] `warp merge`, `WARP_TIMINGS_DIR` CLI parity, read-only artifact support, the version-lock caveat, phpunit.xml discovery (+ `--configuration`), and the clean-break note each appear in the relevant section.
- [x] The documented recipe is semantically identical to the one asserted green in REQ-053's `sh -e` integration test.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `grep -n 'if FILES=\$(' README.md; echo $?`
   - Expected: no matches (exit 1 from grep) — the silent-skip pattern is gone.
2. **runtime** Extract the README's new CI snippet into a script and run it via `sh -e` against a fixture producing exit 0, exit 3, and exit 2 (reuse REQ-053's harness)
   - Expected: runs tests on 0, skips cleanly on 3, fails the script on 2 — the documented recipe matches the tested contract.
3. **test** `./vendor/bin/pest`
   - Expected: full suite green (docs-only change; suite confirms no accidental code edits).

## Integration

**Reachability:** README.md at the repo root — the published package's primary documentation (rawphp/warp), sections "Timing-based sharding" and the CI recipe around README.md:224-256.

**Data dependencies:** Documents the behaviour of `bin/warp` subcommands and the `.warp/timings` artifact layout as shipped by REQ-052/055/058/060.

**Service dependencies:** None (prose); accuracy depends on the four contract-defining REQs it hard-depends on.

## Outputs

- `README.md` — Updated timing/sharding documentation for explicit `warp merge`, read-only shard/timings reads, timing directory parity, phpunit.xml discovery, canonical path keys, exit-code-aware CI sharding, version lock caveat, and pre-UR-011 artifact clean break.
