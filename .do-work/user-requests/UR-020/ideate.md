# Ideate — UR-020

**Reviewed:** 2026-07-28

## Explorer — Assumptions & Perspectives

- **Source of truth is yardpilot issue #271, not a warp-native brief.** The request is a GitHub URL plus “most likely in this warp repo.” Capture must treat the issue body (teardown races in `Dirs::delete` under `WARP_DB` parallel runs) as the real brief; if only the URL is decomposed, REQs will under-specify acceptance and miss recycle/shutdown/stop sequencing.
- **Affected parties go beyond YardPilot’s release gate.** Parallel Pest consumers of `rawphp/warp` with `WARP_MODE` + `WARP_DB`, CI agents, and anyone scanning leftover `/tmp/warp-db/w*` dirs all care: a “green” product suite can still fail on cleanup noise; leftover dirs also burn disk until the next boot/`sweepDeadWorkers`.
- **“Idempotent teardown” is foggy without a failure model.** Issue lists ENOENT on `#ib_redo*_tmp` and `rmdir: Directory not empty`. Undefined: max retries, settle sleep after `mysqld` stop, whether shutdown may leave partial trees, and whether unit tests must simulate concurrent file churn vs. only integration stress.
- **Success metric is “no cleanup-class failures,” not “no leftovers ever.”** Release dry-run must not abort solely on `Dirs.php` unlink/rmdir. Ops may still clear `/tmp/warp-db` occasionally; that is acceptable if tests stay green.

## Challenger — Risks & Edge Cases

- **Bare `unlink`/`rmdir` under Laravel’s error handler turns warnings into hard test fails.** Scenario: worker finishes a green test while `recycle()` or `shutdown()` walks `#innodb_redo`; PHP emits a warning; PHPUnit attributes `ErrorException` to an unrelated test. Matches the issue’s 10 bystander failures.
- **`MysqldServer::stop()` waiting for process exit does not guarantee a quiet datadir.** Scenario: SIGTERM returns, process gone, but kernel/fs still has open or shortly-recreated InnoDB temp names; `Dirs::delete` then hits TOCTOU ENOENT or non-empty `rmdir`. Stop-before-delete alone is not enough without ignore-ENOENT and/or retry.
- **Aggressive `@` / swallow-all can hide real bugs.** Scenario: wrong path, permission denied, or half-deleted tree looks “success” and next clone fails mysteriously. Need ENOENT + retry-on-not-empty scoped, not “never throw.”
- **`sweepDeadWorkers()` can race live siblings if pid/mtime heuristics fail.** Scenario: stale mtime window or reused pid mis-classifies a live worker; delete mid-write. Issue asks for a double-check; widening delete resilience reduces severity but does not remove the live-tree risk.
- **Recreate-concurrency is hard to unit-test faithfully.** Pure unit tests can inject missing files between readdir and unlink; they cannot fully mimic InnoDB. Over-relying on full parallel host suites makes CI flakey and slow; under-testing leaves the race.

## Connector — Links & Reuse

- **Primary code:** `src/Db/Dirs.php` (`delete` recursive walk with bare unlink/rmdir), call sites in `SnapshotDatabaseManager::recycle` / `shutdown` / `sweepDeadWorkers`, plus `CopyOnWriteCloner`, `SnapshotStore`, `GoldenSnapshotBuilder`.
- **Existing coverage:** `tests/Unit/Db/DirsTest.php` (REQ-021) covers happy-path delete and missing-path no-op only — no concurrent-churn or ENOENT-mid-walk cases. `REQ-035` already hardened `recycle()` singleton reset on `stop()` throw; this UR is complementary (filesystem teardown noise, not singleton wedging).
- **Pattern fit:** “warn-and-continue on unlink failure” already appears in decisions for timings merge (UR-011/UR-013). Same spirit applies: cleanup must not fail the caller’s green path. Prefer hardening `Dirs::delete` (shared util) over per-callsite `@` so all consumers benefit.
- **Ship surface:** package version bump + CHANGELOG under Unreleased/next tag so YardPilot can pin/bump; issue acceptance explicitly wants upstream fix then consumer bump.

## Summary

YardPilot #271 is a real, high-confidence race in Warp’s `Dirs::delete` under parallel `WARP_DB` recycle/shutdown: bare unlink/rmdir + post-mysqld-stop InnoDB temp churn becomes `ErrorException` on green tests. Decompose around resilient delete (ENOENT ignore, not-empty retry), optional settle-after-stop, regression unit tests that force mid-walk races, and a changelog/version note for consumers — without swallowing non-ENOENT failures or over-scoping into YardPilot workarounds.

## Lower confidence

- Whether macOS APFS clonefile vs Linux hard-copy changes race frequency enough to need platform-specific settle times.
- Whether a short fixed sleep after stop is enough vs. polling for datadir quiescence (issue suggests either).
