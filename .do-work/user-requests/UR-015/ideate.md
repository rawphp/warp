# Ideate — UR-015

**Reviewed:** 2026-07-09

## Explorer — Assumptions & Perspectives

- The brief assumes every confirmed finding should enter scope, including the lower-severity FileLock diagnostic issue; if capture silently follows the "top-10" framing and drops finding 11, lock failures on read-only mounts will remain opaque even though the brief explicitly says it was confirmed.
- The brief affects package installers as much as CI operators; if the composer constraint bug is treated as only a runtime crash fix, Pest 2 / PHPUnit 10 projects can still install `rawphp/warp` successfully and only discover incompatibility when `warp shard` or `WARP_TIMINGS=1` crashes, which is triggered by finding 3.
- The requested PHPunit-internals fix assumes a compatibility policy that is not stated; if capture does not decide between "require PHPUnit >= 11.1" and "support multiple PHPUnit majors," tests and docs may encode the wrong public contract, which is triggered by finding 3's install-time versus runtime failure.
- Timing capture is implicitly telemetry-only, but the brief does not state whether all timing-write failures should be non-fatal; if only `TimingExtension::flush()` catches write failures, a green suite may still fail through another timing path, which is triggered by finding 2's read-only timings-dir scenario.
- Several bugs need integration-level proof, not only unit fixes; if capture limits the work to package-layer code edits, child PHPUnit/Pest runs, alternate working directories, symlinked suites, and bench crash paths may stay unverified, which is triggered by findings 1, 2, 5, 7, and 10.

## Challenger — Risks & Edge Cases

- Early-stopped runs may not be detectable from static PHPUnit configuration alone; if the fix only extends `hasRestrictedSelection()` and never observes runtime stop state, `stopOnFailure="true"` can still flush a partial FileATest run as complete after t3 fails, which is the core scenario in finding 1.
- Locking `load()` and `fileTotals()` must preserve the standing read-only timing decision; if the fix takes an exclusive merge lock or deletes pending files while sharding, `warp shard` can become a writer or block unexpectedly, yet if it stays lock-free it can still miss a just-unlinked pending batch, which is triggered by finding 4 and the UR-011 decision.
- Accepting files outside `getcwd()` can break timing-key consistency if canonical paths switch between root-relative and absolute forms; a symlinked test directory may shard successfully but never match stored timings, which is triggered by finding 5's outside-root and symlink cases.
- The zero-weight sharder fix must keep deterministic shard agreement across CI nodes; if it uses nondeterministic tie-breaking or a process-local epsilon, all-zero timing artifacts may stop clustering but different nodes could compute different file-to-shard plans, which is triggered by finding 6.
- Option-warning fixes must not pollute machine-readable shard output; if ignored `--suffix` or `--configuration` warnings are written to stdout, downstream CI commands consuming the file list can try to execute warning text as a path, which is triggered by finding 7.
- The bench stale-artifact guard must avoid destructive cleanup of a user's real timings store; if the script simply deletes `.warp/timings` at startup, a benchmark against an application workspace can destroy useful historical timings, which is triggered by finding 10.

## Connector — Links & Reuse

- REQ-073 already established method/group/path-restricted runs flush `complete=false` while plain `--testsuite` remains complete; finding 1 should reuse that semantics and add early-stop detection rather than reopening the completeness model.
- REQ-076 and the UR-011 decision established that `load()` is read-only and junk cleanup belongs only inside explicit merge; finding 4 should reuse the merge-lock boundary while adding a safe read snapshot instead of moving cleanup into shard-time reads.
- `TimingExtension` already routes through `TestFileResolver::resolve($className, $reportedFile, $root)`, and `TestFileResolver` has root-aware caching plus Pest `__filename` support; finding 8 should first prove the inherited-method case still fails in current code, then extend the resolver path with concrete-class regression coverage.
- `DurationBalancedSharder` already centralizes weight calculation, plan creation, and load reporting; finding 6 can be contained there with direct unit coverage instead of adding compensating behavior to `ShardCommand`.
- `ShardCommand` already parses `--suffix`, `--configuration`, and timing options through `TimingStoreArgumentParser` and uses stderr for command errors; finding 7 can reuse that boundary for explicit diagnostics while keeping stdout as the shard-file channel.
- `FileLock` is the narrow place that suppresses the OS error from `fopen`; finding 11 can likely be fixed by preserving `error_get_last()` detail in its RuntimeException without touching callers or changing lock semantics.

## Summary

The main capture risk is treating this as an 11-item flat bug list when several findings share timing-completeness, pending-merge, and discovery contracts that already have standing decisions. Before decomposition, decide the PHPUnit compatibility policy, preserve telemetry/read-only semantics, and require integration proof for early-stop, filesystem, symlink, and bench-artifact scenarios.
