# REQ-103: Root-handshake redesign — shared resolver, root-scoped merge, middle-path mismatch policy

**UR:** UR-017
**Status:** backlog
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 3
**Size:** L
**Files:** src/Support/Paths.php, src/Cli/ShardCommand.php, src/Timing/TimingExtension.php, src/Timing/TimingStore.php, src/Shard/SuiteDiscovery.php, tests/Unit/Cli/ShardCommandTest.php, tests/Unit/Timing/TimingStoreTest.php, tests/Unit/Timing/TimingExtensionTest.php, tests/Integration/Timing/TimingCaptureTest.php, tests/Unit/Cli/MergeCommandTest.php
**Depends on:**

## Task

One design change that kills the root-handshake defect class (findings 3, 4, 7, 9, 15). Four parts:

1. **Shared root resolver.** Add a single `Paths::configRoot(?string $configFile, string $cwd): string` (name at implementer's discretion) implementing the standing UR-016 rule — the canonical timing-key root is the phpunit config file's directory, `dirname(realpath($configFile))` when a config exists, `getcwd()` only when none does. Both `TimingExtension::canonicalRoot()` (write side) and `ShardCommand::suiteRoot()` (read side) must call it; delete the duplicated bodies and the "Mirrors ShardCommand::suiteRoot" comment (finding 15). This fixes finding 4: `suiteRoot()` currently returns raw `getcwd()` for an implicitly discovered phpunit.xml while the extension stamps `dirname(realpath($configFile))`, so a symlinked config diverges the two and every shard exits 2. `SuiteDiscovery` must expose the config path it discovered so ShardCommand can feed the resolver the same file the extension saw.

2. **Explicit-path mode honors --configuration for root computation** (finding 9). Today it prints "--configuration ignored", keeps canonicalRoot=getcwd(), then hard-fails on the mismatch while the error text advises passing the flag just discarded. New behavior: --configuration with explicit paths is still ignored for *discovery*, but IS used for *root computation* via the shared resolver; absent the flag, probe for an implicit config the same way discovery mode does. The "ignored" notice must say discovery-only.

3. **Root-scoped merge** (finding 3). `mergedWithPending()`/`mergeToDisk()` currently let the last pending batch carrying a `root` overwrite the artifact root wholesale (last-writer-wins), so one stray batch from a different config dir flips the stored root and mixes key domains. New behavior: the authoritative root is the existing artifact's root (or the first batch's root when no artifact exists yet); batches whose root differs are warn-and-deleted under the merge lock during `mergeToDisk` (UR-013 junk-batch precedent) and skip-and-warned (never deleted) on the read/load path, which stays strictly read-only.

4. **Middle-path mismatch policy** (finding 7, supersedes UR-016 unconditional fail-loudly — decision confirmed at UR-017 question gate). When `warp shard` finds storedRoot ≠ canonicalRoot: if zero stored keys would match discovered files anyway (pure stale/foreign artifact, e.g. CI cache restored to a renamed workspace), warn and degrade to count-balanced sharding; if stored keys WOULD match discovered files, fail loudly with exit 2 (real misconfiguration worth stopping for). The warning/error must state the two roots and which branch was taken.

Sequencing note inside the REQ: land part 1 (shared resolver) before part 4 (mismatch policy) so policy regressions aren't hidden behind warnings.

## Context

Review findings 3, 4, 7, 9, 15 — five symptoms of one mechanism: the root is computed by two different formulas, stamped as a last-writer-wins flag, and enforced with a hard fail. Captured as ONE design REQ per the UR-016 completeness precedent ("point-fixes on this subsystem repeatedly left adjacent holes; kill the class"). The middle-path policy was chosen at the UR-017 question gate and supersedes the 2026-07-10 UR-016 "mismatch fails loudly" line (superseding decisions.md entry appended by capture). Ideate: fail-loudly protected against silent degradation, degrade protects CI matrices from restored caches — the middle path preserves both protections.

## Acceptance Criteria

- [ ] Write side and read side compute the root through one shared Paths helper; grep shows no `dirname(realpath` duplication in TimingExtension or ShardCommand and the mirror comment is gone
- [ ] A symlinked phpunit.xml (implicit discovery) records and shards with identical roots — shard proceeds duration-balanced, no mismatch (reproduces finding 4 pre-fix)
- [ ] `warp shard 1/2 tests --configuration=config/phpunit.xml` run from the project root with timings recorded under root=<project>/config proceeds duration-balanced; the notice states --configuration is ignored for discovery only (reproduces finding 9 pre-fix)
- [ ] Merging pending batches with two different roots keeps the artifact's existing root, folds in only matching-root batches, warn-and-deletes the foreign batch under the merge lock, and never mixes key domains (reproduces finding 3 pre-fix); on load(), a foreign-root batch is skip-and-warned and left on disk
- [ ] Root mismatch with zero key overlap warns and degrades to count-balanced (exit 0); root mismatch where stored keys match discovered files exits 2 with an error naming both roots
- [ ] All existing shard/timing/merge tests green: `./vendor/bin/pest`

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce finding 4 first: symlinked phpunit.xml — record timings in a child PHPUnit run, then `warp shard` from the same dir; assert no root mismatch (must fail pre-fix with exit 2)
   - Expected: red-then-green; shard output shows duration-balanced plan
2. **test** Reproduce finding 3: merge two pending batches with different roots; assert artifact root unchanged, foreign batch warn-deleted in mergeToDisk, skip-warned (not deleted) in load()
   - Expected: single-root artifact; handoff merge → artifact publish localized
3. **test** Reproduce finding 9: explicit paths + --configuration pointing at a non-cwd config dir with matching recorded root
   - Expected: shard proceeds duration-balanced; notice says ignored-for-discovery-only
4. **test** Middle-path policy branch tests: zero-overlap mismatch → warn + count-balanced exit 0; overlapping-keys mismatch → exit 2 error naming both roots
   - Expected: both branches asserted
5. **test** `./vendor/bin/pest`
   - Expected: all green
