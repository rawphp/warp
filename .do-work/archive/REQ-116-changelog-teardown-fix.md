# REQ-116: Changelog note for WARP_DB teardown race fix

**UR:** UR-020
**Status:** done
**Created:** 2026-07-28
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed commit:34e6642 Unreleased Fixed entry for Dirs::delete/WARP_DB teardown present
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** S
**Files:** CHANGELOG.md
**Depends on:** REQ-114, REQ-115

## Task

Document the WARP_DB datadir teardown race fix under `## Unreleased` in `CHANGELOG.md` so consumers (e.g. YardPilot) can pin/bump with a clear Fixed entry. Describe symptoms (parallel Pest `ErrorException` on `Dirs.php` unlink/rmdir) and the package-side remedy (resilient `Dirs::delete` + post-stop settle). Do not invent a version number or tag — leave under Unreleased until the next release process.

## Context

Issue #271 acceptance: package version bumped + changelog note when fixed upstream. This repo ships via CHANGELOG + git tags (latest documented 0.3.2). User-facing Fixed note closes the consumer communication path; version bump happens at release time, not in this REQ.

## Acceptance Criteria

- [x] `CHANGELOG.md` `## Unreleased` has a **Fixed** bullet describing resilient WARP_DB worker datadir teardown (`Dirs::delete` race-safe + recycle/shutdown sequencing) so parallel suites no longer fail on cleanup `ErrorException`s
- [x] Note references that consumers should upgrade when the next warp release ships (no fabricated version number)
- [x] No unrelated changelog rewrites

## Verification Steps

1. **runtime** `rg -n "Dirs::delete|WARP_DB|teardown|datadir" CHANGELOG.md`
   - Expected: Unreleased Fixed entry present and accurate relative to REQ-114/115 behaviour

## Outputs

- CHANGELOG.md — Unreleased Fixed entry for WARP_DB worker datadir teardown races (Dirs::delete + post-stop settle)
