# REQ-070: Consolidate path canonicalization onto Paths::canonical


**UR:** UR-012
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed all 2 verification checkpoints passed; commit:90bdae9
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** M
**Files:** src/Timing/TestFileResolver.php, src/Support/Paths.php, tests/Unit/Timing/TestFileResolverTest.php, tests/Unit/Support/PathsTest.php
**Depends on:** REQ-067

## Task

`TestFileResolver::canonical()` is a private re-implementation of `Support\Paths::canonical()` (realpath + backslash-normalize + strip-root-prefix + `./`-strip). The recording side (`TestFileResolver`) writes timing keys and the sharding side (`ShardCommand` → `Paths::canonical`) reads them; the two copies MUST produce byte-identical keys or timings silently degrade to count-balanced. Refactor `TestFileResolver` to delegate to `Paths::canonical()` for the normalization, keeping only its own caching layer on top, so there is a single canonicalization implementation.

## Context

Code-review finding #9 (canonicalization half; CONFIRMED duplication, altitude concern). The two hand-copied normalizers can drift independently (symlink handling, trailing-slash, `./`-stripping, Windows drive-letter) — any divergence makes recorded timings match no discovered file, the exact "path-form mismatch" `ShardCommand` already warns about. This is correctness-coupled, so it needs its own tests proving the recording key and the sharding key are identical for the same file — not a drive-by edit. Depends on REQ-067 (same file `TestFileResolver.php`; land the root-aware cache key first).

## Acceptance Criteria

- [x] `TestFileResolver` no longer contains a private copy of the realpath/normalize/strip-root logic; it calls `Support\Paths::canonical()` for normalization and retains its caching wrapper.
- [x] For a representative set of inputs (absolute, root-relative, `./`-prefixed, nested, at-root), the key produced by the recording path equals the key produced by `Paths::canonical` used at shard time (asserted by a test that compares both).
- [x] Behaviour is otherwise unchanged: existing `TestFileResolver` and shard-key tests pass; no change to `Paths::canonical`'s public contract (extend it only if a genuinely missing case is found, with a test).
- [x] Caching hot-path behaviour preserved (no extra realpath calls on repeated resolves).

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="TestFileResolver|Paths|ShardCommand"` — Expected: all pass, including a new test asserting recording-key == shard-key for the same file across the input variants above.
2. **test** `./vendor/bin/pest` — Expected: full suite green (shared canonicalization touches both timing and sharding paths).

## Outputs

- src/Timing/TestFileResolver.php — Replaced duplicated canonicalization logic with a thin Paths::canonical() delegation while preserving resolver caches.
- tests/Unit/Timing/TestFileResolverTest.php — Added shared-key parity coverage across representative inputs and a delegation guard.
