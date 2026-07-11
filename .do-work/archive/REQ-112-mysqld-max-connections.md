# REQ-112: Raise max_connections on the throwaway per-worker mysqld

**UR:** UR-018
**Status:** done
**Created:** 2026-07-11
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** Commit 12bb7c4 adds `--max_connections=1000` to `MysqldServer::flags()`. TDD: new integration test opened 200 simultaneous PDO connections — failed pre-fix with `SQLSTATE[08004] [1040] Too many connections` at ~152, passes post-fix. Full suite 407 passed (SnapshotKey tests green ⇒ no snapshot invalidation), Pint clean.
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Db/MysqldServer.php, tests/Integration/Db/MysqldServerTest.php
**Depends on:**

## Task

Add `--max_connections=1000` to the runtime `mysqld` start flags in `MysqldServer::flags()` (`src/Db/MysqldServer.php`, the `--no-defaults` runtime arg block, currently lines ~108-124), so a warm-worker process running many tests against its ephemeral per-worker `mysqld` cannot exhaust the connection ceiling.

## Context

A YardPilot suite run (`WARP_DB=1` + `WARP_MODE=1`, 12-way paratest) failed with:

```
SQLSTATE[08004] [1040] Too many connections (Socket: /tmp/warp-db/w32140-466e26/mysql.sock)
```

Root cause: `MysqldServer::flags()` boots each throwaway `mysqld` with `--no-defaults` and **no `--max_connections`**, so it falls back to MySQL's compiled-in default of **151**. That default exists to protect long-lived production servers from memory blowout; it is meaningless on an ephemeral, durability-off (`--skip-log-bin`, `innodb_doublewrite=OFF`), single-worker, test-only instance. Under warm-worker mode a single worker process stays alive across hundreds of tests and its `mysqld` accumulates connections past 151.

This is a wrong-default fix, not a leak fix — `SnapshotDatabaseManager::recycle()` already purges the client handle and fully stops the server, so connections reset on recycle. The ceiling is simply mis-tuned for this workload.

**Scope decision (narrow).** The user explicitly chose the narrow fix: correct the default only. A general `warp.db.mysqld_args` config passthrough was considered and **rejected** as speculative and a footgun (arbitrary flags can break snapshot-clone determinism). See `input.md` for the full rationale.

**No SnapshotKey bump.** `--max_connections` is a runtime start flag, not a datadir-initialization flag, so it does not change the golden snapshot's on-disk content. `SnapshotKey` (which hashes migrations/datadir layout, not runtime flags) is unaffected — do **not** bump it.

**Mechanism note for the implementer.** If the reproduction test in step 1 shows connections accumulating *unbounded* per test (rather than a fixed concurrent set), a low `--wait_timeout` to reap idle threads would be the complete companion fix. Confirm the mechanism during step 1; if 1000 comfortably clears a realistic warm-worker file count, `max_connections` alone closes this REQ and any `wait_timeout` work is out of scope here.

## Acceptance Criteria

- [x] `MysqldServer::flags()` emits `--max_connections=1000` in the runtime start arguments.
- [x] A booted `MysqldServer` accepts at least 200 simultaneous open PDO connections on its socket without raising `SQLSTATE[08004] [1040] Too many connections` (this fails against the pre-fix default of 151).
- [x] `SnapshotKey` is unchanged (the runtime flag does not affect datadir content), and the full suite passes.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Add/run an integration test in `tests/Integration/Db/MysqldServerTest.php` that boots a `MysqldServer`, opens 200 simultaneous `PDO` connections to its unix socket, and asserts none throw. Run `./vendor/bin/pest tests/Integration/Db/MysqldServerTest.php`.
   - Expected: the 200-connection test passes; reverting the `flags()` change makes it fail with `[1040] Too many connections` at ~connection 152 (confirms the test actually exercises the ceiling). Test self-skips via the existing `mysqldAvailable()` guard when no `mysqld` is present.
2. **test** Run the full suite: `./vendor/bin/pest`.
   - Expected: all tests green; no regressions in `SnapshotKey`/`SnapshotStore` tests (proves no snapshot invalidation).

## Integration

> Omitted — bug-fix REQ, `**Layer:** none`, no new surface.
