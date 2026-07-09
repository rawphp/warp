# REQ-079: Remove dead sharder path; typed missing-config exception

**UR:** UR-013
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed checkpoints:2 commit:e0bbf54
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** M
**Files:** src/Cli/ShardCommand.php, src/Shard/DurationBalancedSharder.php, src/Shard/MissingConfigurationException.php, src/Shard/SuiteDiscovery.php, tests/Unit/Cli/ShardCommandTest.php, tests/Unit/Shard/DurationBalancedSharderTest.php
**Depends on:**

## Task

Two behavior-preserving cleanups, grouped per the UR-012 cleanup-REQ precedent:

1. **Dead sharder special-case (finding #9):** `DurationBalancedSharder::plan()`'s `allWeightsEqual` branch (src/Shard/DurationBalancedSharder.php:32-38) plus the 16-line helper (54-69) reproduce exactly what the greedy LPT loop already yields for equal weights — `array_search(min($loads), $loads, true)` returns the first minimum index, so files fill bins 0..K-1 then wrap, bit-identical to `$offset % $shards`. Delete the branch and helper; add/keep a test asserting the equal-weight (no-timings fallback) distribution is unchanged, since this is the most-hit path when no timings exist.
2. **Typed missing-config exception (finding #10):** `ShardCommand` selects its "no phpunit.xml → fall back to tests/ discovery" branch by string-comparing `getMessage()` against the literal `'[warp] no phpunit.xml found at project root'` (src/Cli/ShardCommand.php:79) — any reword silently flips the branch. Introduce a dedicated exception (e.g. `Shard\MissingConfigurationException extends RuntimeException`) thrown by `SuiteDiscovery::discover()` for the no-config case and catch it by type in `ShardCommand`; other discovery failures keep throwing plain `RuntimeException` and still abort.

## Context

Code-review findings #9 and #10 — pure refactor, no behavior change intended; folded into one trailing cleanup REQ per the UR-012 decision ("cleanup runs after behavior fixes"). No file overlap with the other UR-013 REQs, so no hard deps; Priority 1 orders it last. Existing usage messages and exit codes must not change.

## Acceptance Criteria

- [x] `allWeightsEqual()` and its branch are removed; a test pins that an all-equal-weights plan (the no-timings fallback case) produces the same bins as before the removal, for at least two (files, shards) shapes including a non-divisible count.
- [x] `SuiteDiscovery::discover()` throws the new typed exception for the missing-config case; `ShardCommand` catches it by type (no `getMessage()` string comparison remains — grep confirms) and the fallback-to-`tests/` behavior with the same stderr notice is preserved.
- [x] A non-missing-config discovery failure (e.g. unloadable configuration) still aborts with exit 2 and the error on stderr — it must NOT trigger the fallback.
- [x] When `--configuration=` is explicitly passed and missing, the command still errors rather than falling back (existing behavior preserved).

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter="DurationBalancedSharder|ShardCommand|SuiteDiscovery"` — Expected: all pass, including the equal-weights distribution pin and the typed-exception fallback cases.
2. **test** `./vendor/bin/pest` — Expected: full suite green (behavior-preserving refactor across the shard path).

## Outputs

- src/Cli/ShardCommand.php — Catches `MissingConfigurationException` by type for implicit no-config fallback while preserving other discovery errors.
- src/Shard/DurationBalancedSharder.php — Removes `allWeightsEqual` branch/helper and preserves original greedy load update semantics.
- src/Shard/MissingConfigurationException.php — Adds typed missing-configuration exception for suite discovery.
- src/Shard/SuiteDiscovery.php — Throws `MissingConfigurationException` for missing implicit `phpunit.xml` / `phpunit.xml.dist`.
- tests/Unit/Cli/ShardCommandTest.php — Covers typed missing-config fallback, non-fallback discovery errors, and explicit missing configuration.
- tests/Unit/Shard/DurationBalancedSharderTest.php — Pins no-timings distribution and mixed positive/zero greedy placement behavior.
