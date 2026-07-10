---
ur: UR-017
received: 2026-07-10
status: intake
---

# UR-017: User Request

## Request

ok, run intake + ideate + capture

[Context: the user asked "what will it take to finish a /code-review with no issues found?" after a high-effort /code-review of branch s3 produced the findings below. The goal of this UR is to fix all confirmed findings so a re-run of /code-review reports zero confirmed correctness findings and no reportable cleanup findings. The user accepted the proposed 4-stream plan and the reviewer's recommendations for the two open design decisions.]

### Verified correctness findings (ranked most severe first)

1. **composer.json:21** — The direct requirement `phpunit/php-file-iterator ^6.0.1` conflicts with every PHPUnit 11.x release (which needs ^5.x), so the advertised `"phpunit/phpunit": "^11.1 || ^12"` support is unsatisfiable for PHPUnit 11 consumers. Verified via `composer why-not phpunit/phpunit 11.5.0`. `ExcludeIterator` exists since file-iterator 5.0 with an identical signature, so relaxing to `^5.0 || ^6.0` is safe.

2. **src/Timing/TimingStore.php:157** — `load()`/`fileTotals()`/`storedRoot()` read timings.json and pending/ without taking merge.lock, so a concurrent `warp merge` makes different shard invocations compute divergent plans — a test file can land on two shards or on none. Fix: acquire the same FileLock around the read path.

3. **src/Timing/TimingStore.php:285** — `mergedWithPending()` lets the last pending batch carrying a 'root' overwrite the artifact root wholesale (last-writer-wins, no comparison), so one stray batch recorded from a different config dir flips the stored root and mixes key domains in one artifact.

4. **src/Cli/ShardCommand.php:170** — With an implicitly discovered phpunit.xml, `suiteRoot()` returns raw `getcwd()` while `TimingExtension::canonicalRoot` stamps `dirname(realpath($configFile))` — the write-side and read-side roots are computed by two different formulas that diverge under symlinks.

5. **src/Timing/TimingExtension.php:131** — A test that errors before being prepared (setUp throws) never fires Test\Finished, so no duration is recorded, but the Errored subscriber still counts it toward per-file completeness — the file is flagged complete and `apply()` drops its prior real timings with no replacement. The Errored event's telemetry `seconds()` is available but unused.

6. **src/Timing/TimingStore.php:101** — `writePending()` returns early when `$tests === []`, silently discarding the complete-files map, so a run whose tests all skipped or errored can never supersede that file's stale timing entries.

7. **src/Cli/ShardCommand.php:119** — A stored-root mismatch hard-fails with exit 2, while every other stale-timings condition (corrupt JSON, wrong version, zero key overlap) deliberately degrades to count-balanced sharding — a restored CI cache from a different workspace path fails the whole shard matrix.

8. **src/Cli/ShardCommand.php:84** — `allowOutsideRoot` is true only in the config-driven discovery branch; the explicit-path and no-phpunit.xml fallback branches leave it false, so the same symlinked or ../-located test file shards fine with a config present but aborts without one.

9. **src/Cli/ShardCommand.php:103** — Explicit-path mode prints '--configuration ignored' and keeps canonicalRoot=getcwd(), but still enforces storedRoot === canonicalRoot — and the mismatch error advises passing the exact --configuration flag this mode just discarded.

10. **src/Timing/TimingStore.php:45** — `absolutize()` only recognizes a leading '/' as absolute (no Windows drive-letter case, unlike its two sibling resolvers), and the --timings-dir CLI path bypasses absolutization entirely via raw `new TimingStore($dir)` in TimingStoreArgumentParser.

### Lower-severity confirmed findings

11. **src/Timing/TimingStore.php:246** — `file_get_contents()` on pending batches has no @/error-handler, so a batch vanishing mid-read leaks PHP's native warning to real STDERR, bypassing the injected sink REQ-101 added for exactly this.
12. **bin/warp:7** — missing autoload fails with an uncaught "class not found" fatal instead of a "run composer install" diagnostic.
13. **tests/Unit/ComposerConstraintTest.php:5** — `composer/semver` is used but only a transitive dependency; add it to require-dev.

### Confirmed cleanup findings

14. Three hand-rolled absolute-path resolvers (`ShardCommand::absolutePath` ≡ `SuiteDiscovery::resolve`, plus the drifted `TimingStore::absolutize`) should be one shared `Paths::absolute()`.
15. `TimingExtension::canonicalRoot` mirrors `ShardCommand::suiteRoot` by comment only — a shared helper would mechanically fix findings 4 and 10's root cause.
16. Six copy-paste anonymous subscriber classes in TimingExtension (identical constructors; Skipped/Errored/MarkedIncomplete bodies identical).
17. `storedRoot()` + `fileTotals()` re-parse the whole store (pending scan + all batches) twice per shard command — no memoization; also a TOCTOU window between the two reads.
18. `TestFileResolver::cacheableClass` duplicates `fileForClass` (second ReflectionClass per class; 4 parallel static caches).
19. Dead `error_get_last()` fallback in FileLock (handler returns true, so error_get_last() is always null on that path).
20. `bench/shard-spread.php` forks `ShardCommand::canonicalFiles()` verbatim including exception/warning strings.
21. Both usage strings (WarpCli.php:50, ShardCommand.php:71) omit the supported `--configuration=` flag.
22. `ShardCommand` `$suffix = ['Test.php', '.phpt']` shadow-copies TestFileFinder::DEFAULT_SUFFIXES (private, can drift), alongside a `$suffixOption` mirror variable.

### Agreed plan (4 streams + quick win)

- **Stream 1 — Root-handshake redesign** (fixes 3, 4, 7, 9, 15): one shared root resolver used by both writer and reader; root mismatch degrades to count-balanced with a warning instead of exit 2; merge becomes root-scoped (reject or segregate batches whose root differs) instead of last-writer-wins; coherent explicit-path mode story.
- **Stream 2 — Store integrity** (fixes 2, 5, 6): take the merge lock on the read path; write batches that carry completeness even when tests === []; record errored-test duration from the Errored event's telemetry `seconds()` rather than zero-weighting the file.
- **Stream 3 — Path absolutization unification** (fixes 10, 8, 14): one `Paths::absolute()` with the Windows drive case, applied in the TimingStore constructor so --timings-dir cannot bypass it; make allowOutsideRoot consistent across all three discovery modes (allow outside-root everywhere).
- **Stream 4 — Minor hardening + cleanup sweep** (fixes 11, 12, 13, 16-22): stderr suppression on pending reads, bin/warp autoload diagnostic, composer/semver in require-dev, --configuration in usage strings, subscriber dedup, merged-snapshot memoization, cacheableClass removal, FileLock dead code, bench dedup, suffix shadow-copy removal.
- **Quick win — composer constraint fix** (fixes 1): relax php-file-iterator to `^5.0 || ^6.0`, update ComposerConstraintTest.

### Accepted design decisions

- Root mismatch: **degrade to count-balanced with a warning**, not hard-fail (matches the code's own stale-artifact philosophy).
- Errored-unprepared tests: **record the telemetry duration from the Errored event** so slow-but-failing files keep realistic weight.

Sequencing: Stream 1 before Stream 3's key-policy change to avoid churning the same files twice. Streams 2 and 4 are independent.

## Clarifications

**Q:** Lock-on-read (finding 2) — how does the fix coexist with the UR-011 read-only CI-artifact-restore guarantee, given FileLock opens its lock file for writing?
**A:** Attempt merge.lock in the read path; fall back to today's lockless read (with vanished-batch tolerance) when the lock file can't be created. One acquisition covers storedRoot + fileTotals together, also killing the TOCTOU between them (finding 17). *(inferred, confirmed)*

**Q:** Root-scoped merge (finding 3) — what happens to pending batches whose root differs from the artifact's?
**A:** Warn-and-delete under the merge lock, never left pending forever — consistent with the UR-013 junk-batch precedent (deletions only in mergeToDisk under the merge lock; load() stays read-only). The authoritative root is the existing artifact's root (or the first batch's when no artifact exists yet). *(inferred, confirmed)*

**Q:** Errored-duration fix (finding 5) — when is the Errored event's telemetry duration recorded?
**A:** Only when the test was never prepared (wasPrepared false). Prepared failing tests already record via Test\Finished; unconditional recording would double-count. A test must prove no double-record. *(inferred, confirmed)*

**Q:** Windows fixes (findings 10/14) — what is the acceptance bar given no Windows CI exists?
**A:** Unit tests with simulated drive-letter inputs; no platform-support claim. *(inferred, confirmed)*

**Q:** REQ grouping — one design REQ or narrow finding-level REQs?
**A:** Stream 1 (root handshake: findings 3/4/7/9/15) is ONE design REQ per the UR-016 completeness precedent ("point-fixes on this subsystem repeatedly left adjacent holes"). Streams 2–4 and the quick win are narrow REQs per the UR-015 precedent, with footprint arbitration and only real hard deps. *(inferred, confirmed)*

**Q:** The brief accepts "root mismatch degrades to count-balanced with a warning" (finding 7), but at yesterday's UR-016 question gate you chose "mismatch fails loudly". Which policy stands when `warp shard` finds storedRoot ≠ canonicalRoot?
**A:** Middle path: degrade to count-balanced with a warning ONLY when zero stored keys would match discovered files anyway (pure stale/foreign artifact, e.g. restored cache from a renamed workspace); fail loudly (exit 2) when keys WOULD match — a real misconfiguration worth stopping for. Supersedes the 2026-07-10 UR-016 unconditional fail-loudly line; capture must append the superseding decisions.md entry.

**Q:** Stream 3 widens allowOutsideRoot to all three discovery modes (finding 8) — what key form do outside-root test files get, given absolute keys never match across machines?
**A:** Root-relative keys with ../ segments (e.g. `../shared/tests/FooTest.php`) in all modes — stable across machines with the same layout, one key domain everywhere.
