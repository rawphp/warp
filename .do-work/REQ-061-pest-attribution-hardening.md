# REQ-061: Path-unit — Pest file-attribution hardening: safe reflection, memoization, loud drop warning

**UR:** UR-011
**Status:** backlog
**Created:** 2026-07-09
**Layer:** package
**Entry point:** `WARP_TIMINGS=1 ./vendor/bin/pest` on a suite containing Pest tests — including a hostile case: a PHPUnit-style test class declaring its own `$__filename` property.
**Terminal state:** The run never crashes from attribution; every unattributable test increments a counter instead of being silently dropped; a nonzero count prints one stderr warning at flush time so a Pest-internals change is noticed immediately.
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** M
**Files:** src/Timing/TestFileResolver.php, src/Timing/TimingCollector.php, src/Timing/TimingExtension.php, tests/Unit/Timing/TestFileResolverTest.php, tests/Unit/Timing/TimingCollectorTest.php
**Depends on:** REQ-050, REQ-055

## Task

Per the UR-011 clarification ("Guard + loud warning"):

1. **Safe reflection** (crash finding): `TestFileResolver` (src/Timing/TestFileResolver.php:17-18) gates `$className::$__filename` on `property_exists()`, which also matches private/instance/uninitialized-typed properties — a userland `$__filename` property throws an uncaught `Error` and kills the run. Replace with `ReflectionClass` checks (`hasProperty` + `isStatic` + `isPublic` + `isInitialized`), falling back to the reported file on any failure; wrap in a defensive catch so attribution can never throw out of the Finished subscriber.
2. **Memoization** (over-cap E1): `resolve()` runs on every test's Finished event with constant per-class inputs — cache results in a static array keyed by class name, and compute the rtrim'd root prefix once instead of per call.
3. **Loud drop counting** (over-cap A1): when `resolve()` returns null (e.g. Pest's `$__filename` internal disappears in a future Pest release and the "eval()'d code" branch yields nothing), `TimingCollector` (src/Timing/TimingCollector.php:27) currently drops the test silently. Count unattributed tests; at flush, `TimingExtension` prints one stderr warning — `[warp] N test(s) could not be attributed to a file; their timings were not recorded` — when the count is nonzero.

## Context

Over-cap findings A1 + crash-PLAUSIBLE + E1. `$__filename` is an undocumented Pest internal (generated in Pest's TestCaseFactory); if it is renamed or dropped, every Pest test silently resolves to null, the timings artifact goes empty, and `warp shard` degrades to count-balanced forever with no signal. The clarified scope keeps reading `$__filename` (no first-class Pest plugin) but makes failure loud and crash-proof.

## Acceptance Criteria

- [ ] A test class declaring `private string $__filename;` (and another with an uninitialized `public static string $__filename;`) passes through `resolve()` without any Error — it falls back to the reported file.
- [ ] `resolve()` results are memoized per class: a spy/counter proves the reflection path runs once for N tests of the same class.
- [ ] When attribution returns null for M tests, flush emits exactly one stderr warning containing the count M; zero unattributed tests emit no warning.
- [ ] Attributed behaviour for normal Pest and PHPUnit classes is unchanged (existing resolver tests pass).

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Timing/TestFileResolverTest.php tests/Unit/Timing/TimingCollectorTest.php`
   - Expected: hostile-property, memoization, and drop-counter tests pass.
2. **runtime** `WARP_TIMINGS=1 ./vendor/bin/pest --filter=WarpModeTest` then `php bin/warp timings --timings-dir=.warp/timings`
   - Expected: timings recorded for the Pest run with no attribution warning on stderr (real Pest attribution still works end-to-end after the reflection change).
3. **test** `./vendor/bin/pest`
   - Expected: full suite green.

## Integration

**Reachability:** `TimingExtension`'s Finished subscriber → `TestFileResolver::resolve()` (src/Timing/TimingExtension.php:61) on every test when `WARP_TIMINGS=1`; warning surfaces on the recording run's stderr.

**Data dependencies:** Pest's generated `$__filename` static (vendor/pestphp internal), PHPUnit's reported test file, and the collector's batch payload (REQ-050's format carries the tests; the unattributed count is process-state, not persisted).

**Service dependencies:** `TimingCollector`/`TimingExtension` flush path as reshaped by REQ-050; canonical keys from REQ-055 (both touch TestFileResolver — hard dependencies serialize the footprint).
