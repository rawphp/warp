---
ur: UR-016
received: 2026-07-10
status: intake
---

# UR-016: User Request

## Request

Batch the 20 confirmed findings into do-work intake

[Context: the 20 findings are the confirmed output of two /code-review rounds run against branch s3 at commit afdad37. The user also accepted the recommendation that the run-completeness subsystem be treated as one design fix rather than 6 patches. The findings, verbatim as reported and confirmed:]

### Round 1 findings (all CONFIRMED by independent verifiers)

1. **src/Timing/TimingExtension.php:36** — Timing keys are canonicalized against getcwd() while `warp shard --configuration=` canonicalizes discovered files against the config file's directory, so the two path forms never intersect when the run cwd differs from the config dir. CI records timings via `pest -c app/phpunit.xml` from the repo root → keys like 'app/tests/FooTest.php'; `warp shard N/M --configuration=app/phpunit.xml` produces 'tests/FooTest.php' (ShardCommand.php:86, suiteRoot at :161). Zero keys match, sharding silently degrades to count-balanced (stderr-only warning, exit 0) — and the printed shard paths are relative to app/, so feeding them back to phpunit from the repo root fails to find the files.

2. **src/Timing/TimingStore.php:168** — In load() (cleanupJunk=false), one failed pending-batch read resets $tests to readMerged() and continues, silently discarding all pending batches already applied earlier in the loop. Pending batches A, B, C where C is unreadable (permissions/EIO, no concurrent merge): after applying A and B, the reset branch reloads bare timings.json (lacking A/B) and continues — A/B vanish from fileTotals() with no warning. Since each shard machine computes the full plan locally (DurationBalancedSharder::assign slices plan()[$index-1]), plans diverge across the fleet and test files get double-run or skipped entirely.

3. **src/Timing/TimingStore.php:168** — During mergeToDisk (cleanupJunk=true), a pending batch whose file_get_contents fails while the file still exists is misclassified as undecodable junk via json_decode('') and permanently unlinked. A batch file is momentarily unreadable (EACCES from a CI uid mismatch, transient EIO) during `warp merge`: the guard `$contents === false && (!$cleanupJunk || !is_file($path))` doesn't fire, json_decode((string)false) sets JSON_ERROR_SYNTAX, the batch joins $mergedPending and is unlinked after timings.json is rewritten without it — recorded timing data destroyed instead of retried.

4. **src/Timing/TimingExtension.php:184** — hasStopOnConfiguration() checks only stopOnDefect/stopOnError/stopOnFailure, missing PHPUnit's stopOnWarning/Risky/Skipped/Incomplete/Notice/Deprecation, so runs halted by those flags are flushed complete=true. The partial batch supersedes the file's prior full timings via TimingStore::apply (TimingStore.php:249-262), permanently underestimating that file's total and skewing every subsequent duration-balanced plan.

5. **src/Timing/TimingExtension.php:89** — PreparationStarted tracks every test but the Finished subscriber returns early for non-TestMethod tests (.phpt emit both events), so their in-flight entries leak and hasInFlight() stays true. Every flush through the shutdown backstop (paratest workers, interrupted runs) is then written complete=false, so complete-batch supersede never purges stale timings; and with any stop-on flag set, finishedTests < selectedTests permanently marks even fully successful runs incomplete.

6. **src/Timing/TimingStore.php:313** — readMerged() throws on undecodable timings.json and ShardCommand converts it to exit 2, hard-failing every shard in the CI matrix, while missing, wrong-version, and key-mismatched timings all degrade gracefully to count-balanced. A truncated CI cache artifact makes the whole matrix stay red until someone manually deletes the cached file, even though sharding needs no timings.

7. **src/Timing/TimingStore.php:240** — apply() accepts any is_numeric 'ms' (e.g. "1e999" → INF, no is_finite guard); mergeToDisk's json_encode(JSON_THROW_ON_ERROR) then throws JsonException, which no CLI catch clause matches (they catch InvalidArgumentException|RuntimeException only), and the poison batch is never cleaned up — PHP dies with exit 255 and every subsequent merge fails identically. No self-heal without manual deletion.

8. **.gitignore:8** — The `.warp/` ignore entry for the feature's default artifact dir exists only as an uncommitted working-tree edit (HEAD's .gitignore ends at /composer.lock), so the branch as committed ships without it. Any dev or CI running `WARP_TIMINGS=1 vendor/bin/pest` gets untracked .warp/timings/*.json in every checkout (also the entry was added without a trailing newline).

9. **src/Timing/TimingStore.php:26** — A relative WARP_TIMINGS_DIR is stored verbatim (fromEnv's getcwd() absolutization covers only the unset-env fallback) and resolved against cwd lazily at write time, including in the shutdown-flush backstop. A chdir() that survives past test execution (tearDownAfterClass, bootstrap, another shutdown handler, or a fatal that skips PHPUnit's runBare cwd restore) makes the backstop write the pending batch under the wrong directory; warp shard/merge read the project dir, find nothing, and silently degrade.

10. **src/Timing/TimingStore.php:178** — TimingStore warnings go through Stderr::write (raw process STDERR, also lines 77, 126, 188) while WarpCli::run and the commands accept injected $stdout/$stderr streams, violating the injected-stream contract. An embedded caller with php://memory streams gets warnings leaked to the host process's real STDERR; MergeCommandTest.php:73-104 already has to shell out via proc_open to observe these messages.

### Round 2 findings (all CONFIRMED by independent verifiers; CLI/discovery items verified empirically)

11. **src/Shard/TestFileFinder.php:43** — RecursiveDirectoryIterator is built without FOLLOW_SYMLINKS while PHPUnit's file iterator follows symlinks, so test files under a symlinked directory are assigned to NO shard and silently never run. Verified empirically: PHPUnit's Facade finds a symlinked test, TestFileFinder::find does not.

12. **src/Shard/SuiteDiscovery.php:98** — configurationPath() probes only phpunit.xml and phpunit.xml.dist, missing PHPUnit's supported phpunit.dist.xml (vendor XmlConfigurationFileFinder.php:60-62 probes phpunit.xml, phpunit.dist.xml, phpunit.xml.dist) and getting precedence wrong when both dist variants exist — warp silently shards a different suite universe than phpunit runs (ignoring <exclude>, custom suffixes, extra suite dirs).

13. **src/Shard/TestFileFinder.php:21** — Only the single suffix 'Test.php' is used for discovery, but PHPUnit's default suffixes for path arguments are ['Test.php', '.phpt'] (vendor Merger.php:866), so .phpt tests are dropped from every shard with no warning — they silently stop running in CI the day sharding is adopted.

14. **src/Timing/TimingStore.php:249** — Complete-batch supersede assumes a complete=true batch saw every test of each file it covers, but paratest --functional splits one file's methods across workers and each worker flushes complete=true (worker argv has no path args and the per-method filter is injected programmatically via $testSuite->injectFilter(), invisible to hasIncompleteSelectionConfiguration), so workers' batches delete each other's halves — the file's stored total is permanently roughly halved. Confirmed against vendored paratest source (SuiteLoader.php:157-159, ApplicationForWrapperWorker.php, WrapperWorker.php:116-152).

15. **src/Timing/TimingExtension.php:48** — executionStarted() overwrites selectedTests with the latest testSuite()->count(), but paratest wrapper workers emit ExecutionStarted once per test file while finishedTests accumulates across the whole worker, so the stop-on completeness gate compares a whole-worker counter to only the last file's count — breaking even the three stop-on flags the code does handle. A stop-on-failure-truncated file flushes complete=true and supersedes its full timings.

16. **src/Timing/TimingExtension.php:83** — Tests skipped before preparation (requirement skips via checkRequirements, markTestSkipped in setUp/before-hooks, setUp errors) emit PreparationStarted but never Test\Finished (PHPUnit gates Finished on wasPrepared(), TestRunner.php:217; TestCase.php:484-520), and the extension has no Skipped/Errored subscriber — each such test permanently leaves an in-flight entry and a finishedTests deficit. One test skipped in setUp() makes every backstop flush complete=false and any stop-on-configured run complete=false even when fully green.

17. **src/Timing/TimingCollector.php:70** — flush() sets $flushed = true BEFORE writePending(), and both the ExecutionFinished path and the shutdown backstop guard on hasFlushed(), so one transient write failure (Dirs::ensure/AtomicFile::write RuntimeException, ENOSPC, JsonException) permanently loses the entire run's timings — the backstop can never act as the retry the comment implies.

18. **src/Timing/TimingStore.php:168** — In mergeToDisk, a pending file that vanishes mid-merge (contents===false && !is_file) resets $tests to readMerged() (discarding batches already applied this pass) but does NOT reset $mergedPending, so the discarded batches' files are still unlinked after publishing a timings.json that lacks their data — an intact, successfully-parsed batch is permanently lost. Distinct from findings 2 and 3 (this path requires file-gone + cleanupJunk=true).

19. **src/Shard/TestFileFinder.php:47** — No hidden-directory filter: files under dot-directories are collected and sharded, while PHPUnit's own iterator rejects any path containing a /.segment/ — stale copies in .history/.cache dirs get passed to phpunit as explicit file args (which bypass phpunit's hidden-dir filtering), producing 'Cannot declare class' fatals or re-running outdated tests as machine-specific flaky CI breaks. Verified empirically.

20. **src/Cli/ShardCommand.php:50** — The shard total is (int)-cast with no upper bound before DurationBalancedSharder::plan() calls array_fill, so oversized totals crash with an uncaught ValueError (extends Error, not caught by catch(InvalidArgumentException|RuntimeException)) or an uncatchable integer-overflow/OOM fatal (exit 255) instead of the documented exit-2 diagnostic. Verified empirically: `warp shard 1/2000000000 tests` dies with 'Fatal error: Possible integer overflow in memory allocation'.

### Design guidance accepted by the user

Treat the run-completeness subsystem as ONE design fix rather than 6 patches: findings 4, 5, 14, 15, 16 (and the completeness aspects of 17) are all holes in the same completeness/supersede design built by REQ-050/069/073/081. Make "complete" file-scoped and event-driven rather than inferred from process-wide counters and stop-on sniffing — kill the class, not the instances.

## Clarifications

**Q:** Does the intended timing workflow stay as documented — record timings only on full unsharded runs, then `warp merge`, then shards restore the artifact read-only?
**A:** Yes, the README workflow stands; finding severity/scoping is assessed against it. *(inferred, confirmed)*

**Q:** Should capture append superseding decisions.md entries for the two collisions — UR-013's completeness semantics (replaced by the redesign) and UR-015's narrow-REQ precedent (deliberate exception for the completeness cluster)?
**A:** Yes, both superseding entries are part of capture's output. *(inferred, confirmed)*

**Q:** Finding 8 (.gitignore `.warp/` entry exists only as an uncommitted working-tree edit) — record as a tiny standalone chore REQ so it isn't lost?
**A:** Yes. *(inferred, confirmed)*

**Q:** Finding 6 — should a corrupt/undecodable timings.json degrade gracefully (warn + count-balanced sharding), consistent with the missing-file and wrong-version paths?
**A:** Yes, degrade gracefully with a warning; never hard-fail the shard matrix over a corrupt timings artifact. *(inferred, confirmed)*

**Q:** Finding 2 (load()'s reset-on-failed-read silently drops already-applied pending batches) collides with the UR-012 decision that dropped an equivalent finding as unreachable ("CI restores committed timings.json only, no pending present at shard time"). Rescope to warn-only, full fix, or drop?
**A:** Full fix — re-litigate UR-012. Treat pending-at-shard-time as reachable (local parallel runs, misconfigured CI); supersede the UR-012 decision line and fix the divergence scenario fully.

**Q:** Which concrete completeness design should capture decompose against, given the accepted "file-scoped and event-driven" guidance?
**A:** Per-file event accounting: track per file the tests enumerated in this process vs tests that reached a terminal event (Finished, Skipped, Errored, MarkedIncomplete — subscribing to all terminal events fixes the skip/phpt leaks by construction). A file is complete when all its enumerated tests terminated. The batch payload carries per-file complete flags; supersede becomes per-file-when-that-file-complete. A paratest worker that saw only part of a file never marks it complete.

**Q:** How should the discovery fixes (findings 11/12/13/19 — symlinks, config filename probing, .phpt suffix, hidden dirs) be shaped?
**A:** Adopt PHPUnit's own iterator: replace TestFileFinder's RecursiveDirectoryIterator with sebastian/file-iterator (already a transitive dep) and extend SuiteDiscovery's probe list to PHPUnit's exact filename order. Parity by construction.

**Q:** How should the CLI error boundary be fixed (findings 6/7/20 — four duplicated catch blocks missing JsonException and ValueError)?
**A:** Hoist one try/catch(Throwable) boundary into WarpCli::run around command dispatch (message to injected stderr, exit 2), deleting the four duplicated blocks. Input validation (shard-total bounds, is_finite ms guard) still added at the source so errors are diagnostic.

**Q:** Which root becomes canonical for timing keys (finding 1 — extension keys against getcwd(), shard against the config file's directory)?
**A:** Config-dir root on both sides: the phpunit.xml directory is the canonical root everywhere; TimingExtension reads the Configuration source path in bootstrap() and keys against dirname(config). Also stamp the root into timings.json so mismatches are detected loudly instead of silently degrading.
