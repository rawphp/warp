# Ideate — UR-014

**Reviewed:** 2026-07-09

## Explorer — Assumptions & Perspectives

- The brief says zero discovered files "needs a distinct failure exit code" but does not say which. Exit 2 already means "usage, discovery, timings, or other shard error" in the README contract, and the documented guard already hard-fails on any non-0/non-3 code — so reusing 2 fixes the CI hole with no guard changes anywhere. Minting a new code (e.g. 4) would force every existing consumer's guard to be revisited. The brief leaves this open; capture should default to exit 2 unless overridden.
- Where the guard lives is undefined: ShardCommand can check `$files === []` after discovery, or SuiteDiscovery/TestFileFinder could throw on an empty result. TestFileFinder is also called by bench/shard-spread.php, so a throw at that depth changes bench behavior too; the ShardCommand-level check is the narrower blast radius, but the deeper fix protects every future consumer. The brief (quoting the verifier) triggers this concern by noting neither discovery class throws on empty.
- Two zero-file entry paths exist, not one: XML/fallback discovery with no paths (ShardCommand.php:74-86) and explicit user paths matching nothing (ShardCommand.php:88, e.g. wrong `--suffix`). The brief's scenario names only the phpunit.xml case; both must fail identically or the explicit-path case remains a silent all-green skip.

## Challenger — Risks & Edge Cases

- The conflated behavior is pinned by tests: WarpBinTest has "exits 3 for an empty shard" (line ~212) and a guard-contract test (line ~279). If either fixture exercises zero-discovered-files rather than more-shards-than-files, the fix will turn those tests red — they must be updated to assert the new exit 2, and a new test must cover the existing-but-empty tests dir scenario. Missing this means the fix ships with failing or falsely-green tests.
- The legitimate exit-3 case must survive: N shards > M files (M ≥ 1) still has shard 1 non-empty and shards M+1..N empty — the guard's skip is correct there. The distinguishing signal is `$files === []` before `assign()`, not `$shard === []` after; putting the check in the wrong place breaks the legitimate skip path.
- The adjacent CONFIRMED review finding (DurationBalancedSharder all-zero weights → whole suite in shard 1, shards 2..N exit 3) produces the same "CI collapses" symptom through a different mechanism and is NOT fixed by this UR. If both land later, they touch neighboring lines in ShardCommand/DurationBalancedSharder — footprint overlap says serialize via hard deps if captured together (UR-012/UR-013 precedent). This brief triggers the concern because its scenario overlaps that finding's symptom.
- The README exit-code table (lines ~268-273) and the guard recipe (~276-293) are a published contract exercised by the integration suite; changing exit semantics without updating the table in the same change reintroduces doc drift (the exact failure class REQ-062 existed to fix). Pre-release clean-break is already standing policy (2026-07-05 decision, zero public users), so no compatibility shim is needed.

## Connector — Links & Reuse

- UR-012/UR-013 precedent (decisions.md): code-review findings are captured as bug-fix REQs grouped by subsystem, serialized only on footprint overlap. This is a single-subsystem, single-mechanism fix — one REQ is the shape prior URs chose.
- WarpBinTest.php already contains the exit-code contract suite (empty-shard exit 3, guard recipe under sh -e) — the new zero-discovered-files test belongs there, reusing its existing fixture/subprocess helpers rather than a new harness.
- ShardCommand already has the exact error-reporting idiom to reuse: `fwrite($stderr, ...); return 2;` (lines 61-65, 101-105) — the fix is one guard clause in that established pattern, no new machinery.
- README "Shard exit codes" table row for 2 already reads "Usage, discovery, timings, or other shard error" — zero-discovered-files fits the existing wording; the change may need only a clarifying phrase plus possibly one line in CHANGELOG.md (repo convention: CHANGELOG updated per change).

## Summary

This is a small, well-bounded bug-fix: add a zero-discovered-files guard that exits 2 (reusing the documented error code and existing stderr idiom) before sharding, covering both the discovery and explicit-paths branches. The two decisions capture must settle are exit-code choice (default: reuse 2) and guard depth (default: ShardCommand, narrowest blast radius). The main execution risk is the pinned tests — WarpBinTest's exit-3 and guard-contract tests must be checked and extended, and the README table updated in the same REQ to avoid contract drift.
