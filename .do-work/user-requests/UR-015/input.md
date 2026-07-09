---
ur: UR-015
received: 2026-07-09
status: intake
---

# UR-015: User Request

## Request

Run intake on the confirmed bugs.

[Context: the user is referring to the confirmed findings from the high-effort code review of branch s3 (vs main) run immediately before this request. The findings are reproduced verbatim from the review output below.]

### Confirmed bugs from the s3 branch review (ranked most severe first)

1. **src/Timing/TimingExtension.php:116** — hasRestrictedSelection() ignores early-stopped runs (stopOnFailure/stopOnDefect/stopOnError), so a partially-executed run is flushed as a complete batch and TimingStore::apply()'s complete-batch branch permanently deletes stored timings for tests that never ran.
   Failure scenario: phpunit.xml has stopOnFailure="true"; FileATest has t1..t10 already merged; t3 fails and the run stops. ExecutionFinished still fires, flush(complete=true) writes a batch covering FileATest with only t1..t3, and apply() deletes every stored ID for that file before upserting the three that ran — t4..t10's durations are silently lost and the file's shard weight collapses, skewing all future shard plans.

2. **src/Timing/TimingExtension.php:101** — flush() lets TimingStore::writePending's RuntimeException (e.g. unwritable timings dir) escape uncaught from both the ExecutionFinished subscriber and the register_shutdown_function backstop, turning a fully green test run into a failing one.
   Failure scenario: WARP_TIMINGS=1 with a read-only .warp/timings dir: Dirs::ensure throws inside the ExecutionFinished subscriber, PHPUnit converts it to a warning that fails the run (failOnPhpunitWarning defaults to true); because $flushed stays false, the shutdown backstop retries and throws uncaught inside a shutdown function — a fatal exit 255. A passing suite reports failure either way over a telemetry-only problem.

3. **composer.json:20** — Runtime src/ code depends on PHPUnit internals (TextUI XmlConfiguration Loader, FileIterator Facade, Configuration::hasExcludeFilter() which only exists since PHPUnit 11.1) but composer.json requires only php ^8.4 with no phpunit/phpunit constraint, so incompatible installs fail at runtime instead of install time.
   Failure scenario: An app on Pest 2 / PHPUnit 10 installs warp (composer allows it) and runs `warp shard` or WARP_TIMINGS=1: Configuration::hasExcludeFilter() doesn't exist and the FileIterator Facade API differs across majors, so bootstrap/discovery crashes with an undefined-method Error instead of composer blocking the install.

4. **src/Timing/TimingStore.php:166** — load()/fileTotals()/mergedWithPending() read timings.json and pending batches without taking merge.lock, racing mergeToDisk() which unlinks pending files after publishing — a batch read mid-merge is silently dropped from the totals.
   Failure scenario: `warp merge` runs concurrently with `warp shard` on the same workspace: the sharder calls readMerged() before merge publishes, then file_get_contents() on a just-unlinked pending file returns false → '' → json_decode error → batch skipped as "undecodable". That plan is computed from fewer timings than one computed moments later, so shard plans across CI nodes diverge and test files run twice or not at all.

5. **src/Cli/ShardCommand.php:137** — canonicalFiles() hard-fails (exit 2, 'test path is outside project root') when any discovered file's realpath falls outside getcwd(), breaking `--configuration=` pointing at another directory and symlinked test directories.
   Failure scenario: Run `warp shard 1/2 --configuration=/repo/app/phpunit.xml` from outside /repo/app, or have tests/Shared be a symlink to a directory outside the project root: PHPUnit's Loader resolves suite dirs relative to the config file, Paths::canonical() returns null for them, and the command aborts with exit 2 instead of sharding.

6. **src/Shard/DurationBalancedSharder.php:34** — LPT assignment never increases a bin's load for zero-weight files, so all files with a recorded 0.0ms total cluster onto one shard; with all-zero weights every file lands in shard 1 and the run exits 3 with a misleading 'more shards than test files' error.
   Failure scenario: timings.json contains ms:0 entries (round(...,3) in TimingCollector can produce 0.0 for sub-0.5µs tests, or a hand-seeded artifact): min($loads) keeps resolving to the same bin, so `warp shard 2/4 tests` puts all N files in shard 1 and shards 2-4 fail their CI jobs with exit 3 despite ample files.

7. **src/Cli/ShardCommand.php:78** — `--suffix=` is silently ignored whenever phpunit.xml discovery is used (no positional paths), and `--configuration=` is silently ignored whenever explicit paths are given — no warning in either case.
   Failure scenario: Project with phpunit.xml: `warp shard 1/2 --suffix=Spec.php` runs SuiteDiscovery with the config's own suffixes, dropping the Spec.php filter without a word; conversely `warp shard 1/2 tests/Unit --configuration=custom.xml` never opens custom.xml. The operator believes a filter was applied when it was not, producing a shard plan over the wrong file set.

8. **src/Timing/TimingExtension.php:67** — Timings are attributed via TestMethod::file() (the declaring file per ReflectionMethod::getFileName), so for plain PHPUnit classes, test methods inherited from an abstract base class or trait are recorded against the base/trait file instead of the concrete *Test.php; only Pest's __filename is corrected.
   Failure scenario: AbstractContractTest defines 20 test methods with 5 concrete subclasses: all 100 tests' timings key to tests/AbstractContractTest.php. ShardCommand discovers the 5 concrete files (which get fallback weights) while the abstract file's large weight either matches no discovered file or lands on a shard where zero tests execute — shard balance is inverted.

9. **src/Timing/TimingStore.php:26** — fromEnv() falls back to getcwd().'/.warp/timings' without checking getcwd() for false, so when the working directory is unavailable the target silently becomes the filesystem-root '/.warp/timings'.
   Failure scenario: The process's cwd is deleted mid-run (or unreadable in a restricted container) with WARP_TIMINGS_DIR unset: getcwd() returns false and concatenates as '', so the shutdown flush either throws '[warp] cannot create directory /.warp/timings' during shutdown or, when running as root, writes timing artifacts to the filesystem root. Other call sites guard with `getcwd() ?: '.'`; this one doesn't.

10. **bench/shard-spread.sh:22** — The pest-failure guard passes if ANY *.json exists under .warp/timings — including a stale timings.json or leftover pending batches from a previous run, which the script never cleans — masking a crashed pest run.
    Failure scenario: Second bench invocation where pest crashes immediately and records nothing: run-1 artifacts satisfy `find ... -name '*.json' -print -quit`, the script suppresses pest's failure, and the S3 gate report presents shard-spread numbers computed entirely from stale data as freshly recorded — notable since this script gates the S3 decision.

11. **src/Support/FileLock.php:14** (confirmed in review but cut from the top-10 report as lowest severity) — FileLock uses @fopen, suppressing the PHP warning that carried the OS reason (e.g. Permission denied); the thrown RuntimeException carries only the path with no error_get_last() detail, so the underlying cause of lock-open failures is lost.
    Failure scenario: Snapshot root exists but the lock file is unwritable (root-owned .lock left behind, read-only mount): the OS reason is silently swallowed and only the generic '[warp] cannot open file lock at ...' message remains, making the failure harder to debug. Appending error_get_last()['message'] to the exception would recover it.
