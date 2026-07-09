---
ur: UR-013
received: 2026-07-09
status: intake
---

# UR-013: User Request

## Request

Fix the findings from the high-effort code review of the `s3` branch (8 finder angles, recall-biased verify). Findings ranked most-severe first:

```json
[
  {
    "file": "src/Timing/TimingStore.php",
    "line": 240,
    "summary": "A 'complete' batch supersedes every stored test for its files, so a filtered/partial re-run silently wipes sibling tests' recorded timings.",
    "failure_scenario": "A full run with WARP_TIMINGS=1 records FileX::testA and FileX::testB. Later the dev iterates: `pest --filter=testA` (still WARP_TIMINGS=1). ExecutionFinished fires normally → writePending({testA}, complete=true). apply() sees complete===true, marks FileX 'covered', unsets ALL existing FileX ids (incl. testB), then re-adds only testA. FileX's stored total now reflects one method, so the sharder under-weights it and packs it onto a heavy shard — the exact skew the balancer exists to prevent."
  },
  {
    "file": "src/Timing/TimingExtension.php",
    "line": 84,
    "summary": "The shutdown backstop classifies any non-fatal abnormal exit (exit()/die()) as a COMPLETE run, so a partial batch is written complete=true and supersedes a whole file's good timings.",
    "failure_scenario": "A test (or code under test) calls exit()/die(). ExecutionFinished never fires; the register_shutdown_function backstop runs $flush(!shutdownHadFatalError()). error_get_last() holds no fatal record, so shutdownHadFatalError()===false → flush(true) writes the collector's partial subset as complete=true. On merge, apply() wipes every prior test id for those files and keeps only the crash subset — permanent loss of the not-yet-run tests' timings."
  },
  {
    "file": "src/Timing/TimingCollector.php",
    "line": 65,
    "summary": "flush() sets $flushed = true BEFORE calling writePending(), so a throwing write loses the batch and permanently suppresses the shutdown backstop.",
    "failure_scenario": "ExecutionFinished → flush(): line 65 sets flushed=true, then line 66 writePending() throws (ENOSPC/EACCES, short write, failed rename, or random_bytes failure). The exception unwinds out of the subscriber. The register_shutdown_function backstop later calls flush() again but hasFlushed() is now true → it returns without retrying. The entire worker's timings are dropped even though the failure may have been transient."
  },
  {
    "file": "src/Timing/TimingStore.php",
    "line": 77,
    "summary": "mergeToDisk() checks only file_put_contents() !== false, not a short/partial write, so a truncated timings.json can be atomically published and corrupt the store.",
    "failure_scenario": "During `warp merge` on a near-full disk (or interrupted write) file_put_contents writes some bytes and returns an int < strlen(json), not false. The code proceeds to rename() the truncated tmp over timings.json. Every later `warp shard`/`warp timings` then throws '[warp] cannot decode timings ...' until the file is deleted by hand. Note writePending() at line 47 guards this with `$bytes < strlen($encoded)`; mergeToDisk does not — the two atomic-write copies have drifted."
  },
  {
    "file": "src/Timing/TimingStore.php",
    "line": 89,
    "summary": "In mergeToDisk, an unlink() failure on a merged pending file throws AFTER timings.json is published, turning that file into a poison batch that re-throws on every future merge.",
    "failure_scenario": "timings.json is atomically published, then the delete loop removes merged pending files. If unlink() fails for one (read-only file, NFS/permission issue, concurrent removal), line 89 throws. Surviving pending files remain; every subsequent mergeToDisk re-reads them and hits the same undeletable file, throwing again — `warp merge` is permanently wedged in CI."
  },
  {
    "file": "bench/shard-spread.php",
    "line": 31,
    "summary": "Bench passes RAW finder paths as $files but CANONICAL keys from fileTotals() as $totals, so timing lookups miss and every file collapses to the fallback weight.",
    "failure_scenario": "ShardCommand canonicalizes discovered files before assign(); bench does not. TestFileFinder::find() returns paths in the form given (absolute when the suite arg is absolute, or when run from a subdir), while fileTotals() keys are root-relative canonical. Run bench with an absolute suite path: weights()'s array_intersect_key is empty → every file gets fallback 1.0 → allWeightsEqual round-robin. The 'warp LPT' column shows a meaningless equal spread while appearing to succeed, defeating the S3 gate report."
  },
  {
    "file": "src/Cli/TimingStoreArgumentParser.php",
    "line": 29,
    "summary": "`--timings-dir=` with an empty value builds `new TimingStore('')`, bypassing fromEnv()'s empty-string guard, so the store probes/writes at the filesystem root.",
    "failure_scenario": "fromEnv() deliberately falls back to getcwd().'/.warp/timings' when the dir is '', but the parser's `--timings-dir=` branch has no such guard. `warp shard 1/2 --timings-dir=` (e.g. an unset CI var expanding to empty) yields dir='' → load() probes is_dir('/pending')/is_file('/timings.json') and mergeToDisk/writePending Dirs::ensure('/pending') at the root. Inconsistent with `--suffix=` which throws on empty; the user silently gets count-balanced sharding instead of an error."
  },
  {
    "file": "src/Timing/TimingStore.php",
    "line": 187,
    "summary": "Undecodable or non-array pending batches are skipped but never added to $mergedPending, so they are never deleted and are re-parsed (and re-warned) on every load/merge forever.",
    "failure_scenario": "A pending *.json that fails json_decode, or decodes to a scalar/null (is_array false at line 187), is skipped without being appended to $mergedPending. mergeToDisk only unlinks $mergedPending entries, so the junk file survives every `warp merge` (which reports a misleading 'merged N' or 'nothing to merge') and is re-read + re-emits '[warp] skipped ... pending timings batch' to stderr on every shard/timings invocation, never self-healing."
  },
  {
    "file": "src/Shard/DurationBalancedSharder.php",
    "line": 32,
    "summary": "The allWeightsEqual special-case path (plus its 16-line helper) is redundant — the LPT loop already yields the identical deterministic round-robin for equal weights.",
    "failure_scenario": "With equal weights, array_search(min($loads), $loads, true) returns the first minimum index, so files fill bins 0..K-1 then wrap — bit-identical to `$offset % $shards`. The branch (32-38) and allWeightsEqual() (54-69) change nothing behaviorally; they add an O(n) scan on every plan() call and a parallel algorithm that must be kept in sync with the greedy loop's tie-break or the two 'equal weight' results silently diverge. This is the most-hit path in the common no-timings case."
  },
  {
    "file": "src/Cli/ShardCommand.php",
    "line": 79,
    "summary": "The 'no phpunit.xml → fall back to tests/' branch is selected by matching the exception's exact message string rather than a typed exception.",
    "failure_scenario": "SuiteDiscovery::discover() throws a generic RuntimeException; ShardCommand separates benign missing-config from real failures (unloadable config, missing suite dir/file) solely by string-comparing getMessage() against '[warp] no phpunit.xml found at project root'. Any reword of that literal — typo fix, punctuation, adding the searched path — silently flips the branch, turning a benign missing-config into a hard error (or vice-versa), with no test/compiler coupling to catch it. A dedicated MissingConfigurationException caught explicitly is the right depth."
  }
]
```

Additional review notes:

- Findings 1 and 2 share a defect surface: `apply()`'s complete-batch supersede is too aggressive, and "completeness" is mis-detected for both filtered runs (finding 1) and `exit()`/`die()` abnormal exits (finding 2). The dead `|| ! self::shutdownHadFatalError()` in `TimingExtension.php:101` can never demote a `complete=true` flush and gives false reassurance that completeness is re-validated at flush time — it should be removed as part of the fix.
- Findings 1–3 are silent timing-data loss that degrades shard balance invisibly (no error, just skew) — the one failure mode this tool must not have. Prioritize these.
- Finding 4 pairs with a reuse observation: the atomic tmp-write + rename publish sequence is duplicated between writePending() and mergeToDisk() and has already drifted (short-write guard exists in one, not the other). Prefer extracting a shared atomic-write helper so the guard exists once.
