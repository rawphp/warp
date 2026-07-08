---
ur: UR-011
received: 2026-07-09
status: intake
---

# UR-011: User Request

## Request

run intake on the issues found

[Context: "the issues found" refers to the findings of the /code-review run on the s3 branch, delivered immediately before this request. The findings are reproduced verbatim below.]

Review of the s3 branch (~1,700 lines: timing-based test sharding — TimingStore, PHPUnit extension, sharder, CLI). 19 of 22 deduped candidates survived verification; the 10 most severe, correctness first. The overall theme: the timing pipeline's happy path works, but nearly every degraded path fails **silently** — mismatched paths, missing env vars, glob-hostile directories, and CI errors all quietly fall back to count-balanced sharding or skip tests entirely while exiting green.

```json
[
  {
    "file": "src/Cli/ShardCommand.php",
    "line": 46,
    "summary": "Timing keys are stored cwd-relative but TestFileFinder returns paths as-given, so `warp shard 1/8 ./tests` (or an absolute path) matches zero stored timings and silently degrades to count-balanced sharding — the 'no recorded timings' warning is suppressed because it only checks `$totals === []`, and machines invoked with different path spellings compute divergent shard plans, breaking the no-coordination invariant (tests double-run or never run).",
    "failure_scenario": "Timings keyed 'tests/Unit/FooTest.php' (TestFileResolver strips getcwd()); CI runs `warp shard 1/8 ./tests` → discovered files are './tests/Unit/FooTest.php' → array_intersect_key in DurationBalancedSharder.php:26 matches nothing → every weight falls back to 1.0 with no warning since $totals !== []."
  },
  {
    "file": "README.md",
    "line": 254,
    "summary": "The recommended CI snippet `if FILES=$(warp shard ...); then pest $FILES; fi` conflates error exit 2 with empty-shard exit 3, so any shard-command failure silently skips that shard's entire test run and CI passes green.",
    "failure_scenario": "One node restores the timings cache with bad permissions (or has a typo'd path) → ShardCommand exits 2 → the `if` is false exactly as for a legitimately empty shard → pest never runs, `set -e` is suppressed inside the condition, job exits 0 with zero tests executed."
  },
  {
    "file": "src/Timing/TimingStore.php",
    "line": 49,
    "summary": "`warp shard` performs destructive writes on its read path — mergePending() opens merge.lock BEFORE checking whether any pending files exist, rewrites timings.json, and unlinks pending/*.json on every shard machine — so a read-only restored CI artifact makes every shard fail with exit 2.",
    "failure_scenario": "README tells users to persist .warp/timings wholesale (pending/ included, since nothing merges after recording); 8 shard machines restore it read-only/root-owned → fopen(merge.lock,'c') fails → RuntimeException → exit 2 → combined with the README guard, all 8 shards silently skip their tests."
  },
  {
    "file": "src/Timing/TimingExtension.php",
    "line": 82,
    "summary": "The register_shutdown_function backstop flushes a partial batch after a fatal error, and TimingStore::apply()'s supersede-by-file semantics then delete all previously recorded complete entries for the in-flight file, corrupting its weight.",
    "failure_scenario": "A file with 50 tests totaling 5000ms is in timings.json; a recording run fatals (OOM) on test 3 → shutdown flush writes a 2-test ~200ms batch → next merge wipes the other 48 entries (TimingStore.php:141-149) → the file is weighted 200ms and packed with heavy files, blowing out one shard's wall clock."
  },
  {
    "file": "src/Timing/TimingStore.php",
    "line": 64,
    "summary": "mergePending() applies pending batches in lexicographic filename order ('{pid}-{randomhex}.json', no timestamp), so when two recording runs accumulate before a merge, an older run's batch can sort after — and supersede — the newer run's timings for the same files.",
    "failure_scenario": "Run 1 (pid 8123) records ATest.php at 5000ms; run 2 (pid 4507) re-records it at 50ms after a speedup; next `warp shard` sorts '4507-*' before '8123-*' → the stale 5000ms batch is applied last and wins; deleted tests can also be resurrected. Fix: timestamp in the filename or sort by mtime."
  },
  {
    "file": "src/Cli/ShardCommand.php",
    "line": 24,
    "summary": "ShardCommand and TimingsCommand hardcode '.warp/timings' and never consult the WARP_TIMINGS_DIR env var that TimingStore::fromEnv() honors on the write path, so recording and reading default to different locations.",
    "failure_scenario": "CI records with WARP_TIMINGS_DIR=/cache/warp-timings; `warp shard 1/8 tests` (no --timings-dir) reads ./.warp/timings, finds nothing, prints a one-line note and exits 0 with count-balanced sharding — the feature is silently inert. Fix: default both commands to TimingStore::fromEnv()."
  },
  {
    "file": "src/Timing/TimingStore.php",
    "line": 37,
    "summary": "writePending() is commented 'single atomic write' but uses plain file_put_contents (no tmp+rename, no lock shared with readers), and mergePending() unconditionally unlinks any pending file that fails json_decode — a merge racing a writer reads a truncated file and permanently destroys that worker's batch.",
    "failure_scenario": "A straggler paratest worker's shutdown flush is mid-write when `warp shard`/`warp timings` merges the same dir: file_get_contents at line 69 reads partial JSON → json_decode returns null → line 75 unlink()s the file anyway → timings silently lost. Fix: write to .tmp then rename; skip rather than unlink undecodable files."
  },
  {
    "file": "src/Timing/TimingStore.php",
    "line": 58,
    "summary": "mergePending() globs $this->dir.'/pending/*.json' without escaping the directory, so a project or WARP_TIMINGS_DIR path containing glob metacharacters ([, ], *, ?) matches nothing and pending batches are silently never merged.",
    "failure_scenario": "Checkout under /home/ci/job[1]/app → '[1]' parses as a character class → glob returns [] (the ?: [] also masks false) → writePending keeps accumulating batches that load() never merges → `warp timings` reports 'no timings recorded yet' forever despite files sitting in pending/."
  },
  {
    "file": "src/Shard/TestFileFinder.php",
    "line": 43,
    "summary": "Shard discovery hardcodes the 'tests' directory and 'Test.php' suffix instead of reading phpunit.xml testsuites — which TimingExtension::bootstrap() is already handed and ignores — so shard plans diverge from what the runner actually executes.",
    "failure_scenario": "A project with multiple suite roots (modules/*/tests), a custom suffix=, <file> entries, or <exclude> blocks: excluded files get distributed to shards and run anyway, while files outside tests/ or with a nonstandard suffix are assigned to no shard and silently never run in sharded CI — every shard green."
  },
  {
    "file": "src/Cli/TimingsCommand.php",
    "line": 30,
    "summary": "TimingsCommand::run() calls TimingStore::load() with no try/catch, so the RuntimeException mergePending() throws when the merge lock cannot be opened escapes as an uncaught fatal (stack trace, exit 255), while ShardCommand catches the identical exception and exits 2 cleanly.",
    "failure_scenario": "A restored read-only timings artifact still containing pending/ → `warp timings` enters mergePending → fopen(merge.lock,'c') fails → uncaught '[warp] cannot open timings lock' with a PHP stack trace and exit 255; neither WarpCli::run() nor bin/warp has a top-level catch."
  }
]
```

Nine verified findings didn't fit the cap of 10, most notably: `TestFileResolver` depends on Pest's undocumented `$__filename` internal and silently drops every Pest test from timings if it fails (and a userland `$__filename` property can crash the run — PLAUSIBLE); the bench script duplicates the sharder's private fallback-weight policy and crashes on zero discovered files; `WarpMode`'s env-flag idiom is triplicated; `TimingStore` re-implements `SnapshotStore::withLock()`; and the resolver runs unmemoized reflection on every test finish.
