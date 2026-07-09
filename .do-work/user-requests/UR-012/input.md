---
ur: UR-012
received: 2026-07-09
status: captured
classification: bug-fix
layers_in_scope: []
layer_decisions: {}
reqs:
  - { id: REQ-063, layer: none, integration_confidence: n/a }
  - { id: REQ-064, layer: none, integration_confidence: n/a }
  - { id: REQ-065, layer: none, integration_confidence: n/a }
  - { id: REQ-066, layer: none, integration_confidence: n/a }
  - { id: REQ-067, layer: none, integration_confidence: n/a }
  - { id: REQ-068, layer: none, integration_confidence: n/a }
  - { id: REQ-069, layer: none, integration_confidence: n/a }
  - { id: REQ-070, layer: none, integration_confidence: n/a }
  - { id: REQ-071, layer: none, integration_confidence: n/a }
acknowledged_partials: []
---

<!-- capture-summary-start -->
## Capture summary (2026-07-09)

| Item | Value |
|---|---|
| Classification | bug-fix |
| Layers in scope | (none — bug-fix) |
| Layer decisions | (none — all covered) |
| REQs generated | 9 |

| REQ | Layer | Integration confidence |
|---|---|---|
| REQ-063 | none | n/a |
| REQ-064 | none | n/a |
| REQ-065 | none | n/a |
| REQ-066 | none | n/a |
| REQ-067 | none | n/a |
| REQ-068 | none | n/a |
| REQ-069 | none | n/a |
| REQ-070 | none | n/a |
| REQ-071 | none | n/a |

Notes: finding #1 (shard reads pending overlay) dropped as unreachable — see `.do-work/decisions.md` (grilled at question gate; reaffirms UR-011/REQ-051). REQ-068/069 must reconcile against archived REQ-048/050 before changing merge machinery. Cleanup REQ-070/071 ordered last via deps.
<!-- capture-summary-end -->

# UR-012: User Request

## Request

Fix the correctness bugs found in the `/code-review` of branch `s3` (the new sharding/timing CLI: TimingStore, TimingExtension, TimingCollector, TestFileResolver, SuiteDiscovery, DurationBalancedSharder, TestFileFinder, ShardCommand, MergeCommand, TimingsCommand, WarpCli, FileLock, SnapshotStore, Paths). Each finding below was verified by an independent verifier; the verdict is noted per item.

### 🔴 Correctness (CONFIRMED)

**1. Sharding reads unmerged pending timings → machines compute divergent plans → tests silently skipped (green CI)** `src/Cli/ShardCommand.php:73`
`ShardCommand` gets weights via `$store->fileTotals()` → `load()`, which folds in `.warp/timings/pending/*` batches in-memory (`TimingStore.php:101-107`); it never merges-to-disk first. Two shard machines with non-identical pending state (a stale batch, or `WARP_TIMINGS=1` set on one) compute different LPT partitions of the *same* file list. A file assigned to shard 2 by machine-A but shard 0 by machine-B is executed by **no** shard and never runs — with a green build. Sharding should read only committed `timings.json` (`readMerged()`), not the pending-inclusive `load()`.

**2. `promote()` throws an unimported `RuntimeException` → fatal "class not found" Error** `src/Db/SnapshotStore.php:46`
The `FileLock` refactor deleted `use RuntimeException;` (diff replaced it with `use RawPHP\Warp\Support\FileLock;`) but line 46 still throws unqualified `RuntimeException`, which now resolves to the non-existent `RawPHP\Warp\Db\RuntimeException`. A failed snapshot `rename()` raises a fatal `Error` instead of the catchable exception; the diagnostic message is lost and `catch (RuntimeException)` callers miss it. Fix: re-add `use RuntimeException;` (or use `\RuntimeException`).

**3. Under paratest/`pest --parallel`, no batch is ever `complete=true` → stale timings for deleted/renamed tests accumulate forever** `src/Timing/TimingExtension.php:83`
`complete=true` is produced only by `ExecutionFinished` (`:71`), which parallel workers may never receive — they flush via `register_shutdown_function(... flush(false))`. The `complete` flag is the *only* gate for the file-supersede logic in `TimingStore::apply()` (`:252`) that prunes stale test IDs. Since the documented workflow (`bench/shard-spread.sh`) is `pest --parallel`, deleted tests' `ms` keeps inflating file totals and skewing shard balance until a `VERSION` bump. Self-heals only if a single-process run ever executes.

**4. Short/partial write produces a truncated batch that is silently dropped → timing data lost** `src/Timing/TimingStore.php:45`
`writePending()` checks `file_put_contents(...) === false` only. On ENOSPC a partial write returns a positive byte count (passes the check), and the truncated `.tmp` is `rename()`d into `pending/`. The merge path (`:190`) hits a `json_decode` error, warns "skipped undecodable pending timings batch", and `continue`s — and never unlinks it, so the corrupt batch is skipped forever and its timing data lost. Verify the byte count against `strlen()`.

**5. `--suffix=` (empty) makes `str_ends_with` match every file** `src/Shard/TestFileFinder.php:43`
`ShardCommand.php:37` parses `--suffix=` into `''` with no validation. `str_ends_with($name, '')` is always true, so READMEs, JSON fixtures, and snapshots get collected as "test files" and handed to the runner / distort duration weighting. Reject an empty suffix.

**6. `flock()` return value ignored → no mutual exclusion if locking fails** `src/Support/FileLock.php:20`
`flock($handle, LOCK_EX);` discards its result; the callback runs even if `flock` returns false (possible on some NFS/overlay/container filesystems). Two concurrent `mergeToDisk()` / golden-build critical sections then run simultaneously — the exact race the lock exists to prevent. Check the return and throw if it fails.

### 🟡 Plausible / lower-severity

**7. All-zero weights collapse the whole suite into shard 0** `src/Shard/DurationBalancedSharder.php:34`
With every weight `0.0`, `array_search(min($loads), $loads, true)` always returns index 0, so all files pile into shard 0 and the rest exit empty. Mechanism confirmed; trigger requires timings that are all exactly `0.0` (the no-timings path correctly falls back to `1.0`), which is hard to reach via the normal collector but reachable with malformed/all-zero data.

**8. `TestFileResolver::resolve()` memoizes keyed on class name, ignoring `$root`** `src/Timing/TestFileResolver.php:35`
`$resolvedByClass[$className]` omits `$root` from the key, so resolving the same class under a second root in one process returns the stale first-root relative path (→ timing keys that never match at shard time). Latent today — the single caller passes an immutable `$root` — but a footgun if a long-lived worker ever handles two roots.

### 🧹 Cleanup

**9. `TestFileResolver::canonical()` re-implements `Support\Paths::canonical()`** `src/Timing/TestFileResolver.php:108`
Path canonicalization exists in two hand-copied places. The recording side (this) and the sharding side (`Paths::canonical` via `ShardCommand`) must produce byte-identical keys or timings silently degrade to count-balanced — yet they can drift independently. `TestFileResolver` should wrap `Paths::canonical` (adding its own caching) rather than duplicate the body. Same coupling risk for the duplicated `warn()` helper (`TimingStore.php:168` / `TimingExtension.php:109`) and the verbatim `--timings-dir` arg-parse loop across Merge/Timings/Shard commands.

**10. Dead code: `TimingStore::mergePending()` has no callers** `src/Timing/TimingStore.php:56`
Grep across src/bin/bench/tests shows zero callers; it only wraps `mergeToDisk()` and discards its return. Also `ShardCommand.php:60` resolves `phpunit.xml` twice (once for the fallback message, again inside `discover()`) — a redundant filesystem scan per invocation.

### Notes
- The phpunit.xml `<directory>` wildcard concern was REFUTED — `phpunit/php-file-iterator` does expand globs.
- Fix priority before merge: #1 (silent dropped tests) and #2 (uncatchable fatal on promote failure) are the two highest-impact.
