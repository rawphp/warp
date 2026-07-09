# REQ-054: Path-unit — canonical path keys: identical shard plans for any path spelling

<!-- claimed-start -->
**Claimed by:** codex-main
**Claimed at:** 2026-07-09T02:44:23Z
**Heartbeat:** 2026-07-09T02:44:23Z
<!-- claimed-end -->

**UR:** UR-011
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Entry point:** `./vendor/bin/warp shard k/n <paths>` invoked with any spelling of the same test tree — `tests`, `./tests`, or an absolute path — on any machine holding the same timings artifact.
**Terminal state:** All spellings produce byte-identical, duration-balanced shard plans; when recorded timings exist but no discovered file matches them, a loud stderr warning names the mismatch instead of silently degrading to count-balanced packing.
**Parent:**
**Closure proof:** checkpoint_log:passed verification checkpoint passed after child REQs REQ-055 through REQ-057 were integrated; full suite green (240 tests, 627 assertions) commit:db5961d
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** S
**Files:**
**Depends on:** REQ-055, REQ-056, REQ-057

## Task

Closure REQ for the canonical-path sharding path. Owns no code; closes when REQ-055 (canonical keys + mismatch warning), REQ-056 (sharder plan/weights API), and REQ-057 (bench consumes the API) are committed and the acceptance below holds.

## Context

Review finding #1 (the most severe): timing keys are cwd-relative while discovery returns paths as-given, so exact-string lookup misses for every non-identical spelling — the entire duration-balancing feature goes silently inert, and mixed spellings across CI nodes compute divergent plans (tests double-run or never run). Over-cap findings A4/bench ride along: the sharder exposing its weight/plan computation lets the bench stop duplicating the fallback policy.

## Acceptance Criteria

- [x] All child REQs (REQ-055, REQ-056, REQ-057) are committed.
- [x] `warp shard 1/4 tests`, `warp shard 1/4 ./tests`, and `warp shard 1/4 "$PWD/tests"` against the same recorded timings produce byte-identical stdout (asserted by a REQ-055 test).

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest`
   - Expected: full suite green, including the path-spelling equivalence tests from REQ-055.

## Outputs

- No code outputs — closure REQ for the canonical-path sharding path after REQ-055 through REQ-057 landed.
