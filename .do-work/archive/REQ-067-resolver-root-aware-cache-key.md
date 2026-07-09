# REQ-067: Make TestFileResolver cache key root-aware


**UR:** UR-012
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed all 1 verification checkpoints passed; commit:54f056e
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Timing/TestFileResolver.php, tests/Unit/Timing/TestFileResolverTest.php
**Depends on:**

## Task

`TestFileResolver::resolve()` memoizes the resolved root-relative path in `self::$resolvedByClass[$className]`, keyed only by class name — the `$root` argument is not part of the key. Resolving the same class under a different `$root` within one process returns the first root's cached relative path. Include `$root` in the cache key (e.g. `$root."\0".$className`) so the memo is correct per (root, class) pair.

## Context

Code-review finding #8 (PLAUSIBLE, downgraded). Latent today: the single caller (`TimingExtension`) captures `$root` once as an immutable `readonly` property, so two roots never occur in one process. But the missing-root key is a footgun for any future long-lived worker that records timings across two project roots — the second root's timing would be mis-keyed and never match at shard time (silent cache miss → count-balanced fallback). Cheap, correctness-preserving hardening; keep the caching behaviour otherwise identical.

## Acceptance Criteria

- [x] The resolver cache key incorporates `$root`, so `resolve($class, $file, $rootA)` and `resolve($class, $file, $rootB)` (same class, different roots) each compute and return the correct root-relative path rather than sharing a stale entry.
- [x] Repeated calls with the same (class, root) still hit the cache (memoization preserved — no extra realpath work on the hot path).
- [x] Existing `TestFileResolverTest` cases pass unchanged.

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter=TestFileResolver` — Expected: all pass, including a new case that resolves the same class under two distinct roots in one process and asserts each returns its own root-relative path (not the first root's cached value).

## Outputs

- src/Timing/TestFileResolver.php — Changed resolved-path memoization to key by root and class pair.
- tests/Unit/Timing/TestFileResolverTest.php — Added coverage for distinct roots and preserved same-root memoization.
