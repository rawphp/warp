---
ur: UR-018
received: 2026-07-11
status: captured
classification: bug-fix
layers_in_scope: []
layer_decisions: {}
reqs:
  - { id: REQ-112, layer: none, integration_confidence: n/a }
acknowledged_partials: []
---

<!-- capture-summary-start -->
## Capture summary (2026-07-11)

| Item | Value |
|---|---|
| Classification | bug-fix |
| Layers in scope | (none — bug-fix) |
| Layer decisions | (none — bug-fix) |
| REQs generated | 1 |

| REQ | Layer | Integration confidence |
|---|---|---|
| REQ-112 | none | n/a |
<!-- capture-summary-end -->

# UR-018: User Request

## Request

See if we can fix this.

```
  FAILED  Tests\Api\WorkRequests\Edi…  QueryException
  SQLSTATE[08004] [1040] Too many connections (Connection: mysql_testing, Socket: /tmp/warp-db/w32140-466e26/mysql.sock, Database: yardpilot_test_test_1, SQL: select exists(select * from `permissions`) as `exists`)

  at vendor/laravel/framework/src/Illuminate/Database/Connectors/Connector.php:67
     63▕     protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
     64▕     {
     65▕         return version_compare(PHP_VERSION, '8.4.0', '<')
     66▕             ? new PDO($dsn, $username, $password, $options)
  ➜  67▕             : PDO::connect($dsn, $username, $password, $options); /** @phpstan-ignore staticMethod.notFound (PHP 8.4) */
     68▕     }
     69▕
     70▕     /**
     71▕      * Handle an exception that occurred during connect execution.

      +13 vendor frames
  14  tests/RefreshDatabaseWithPermissions.php:58
  15  tests/RefreshDatabaseWithPermissions.php:36
```

[Context: this failure surfaced while running the YardPilot test suite, which consumes `rawphp/warp` v0.3.0 with `WARP_DB=1` (S2 golden-snapshot DB provisioning) and `WARP_MODE=1` (warm-worker), 12-way paratest parallelism. The requested fix framing agreed with the user: "Raise max_connections on the throwaway mysqld (default 151 exhausted under 12-way parallel warm-worker load), and optionally expose a mysqld-args config knob."]

### Diagnosis (pre-intake)

- **Root cause:** `MysqldServer::flags()` (`src/Db/MysqldServer.php:108`) boots every throwaway per-worker `mysqld` with `--no-defaults` and **no `--max_connections`**. `--no-defaults` makes it ignore any `my.cnf`, so it falls back to MySQL's compiled-in default `max_connections = 151`. Under warm-worker mode a single worker process stays alive across hundreds of tests, and that worker's ephemeral `mysqld` exhausts its 151-connection ceiling → `SQLSTATE[08004] [1040]`. The failure surfaces on the first query after a connect (`Permission::query()->exists()` in the consumer's `RefreshDatabaseWithPermissions:58`).
- **Not a leak across recycles:** `SnapshotDatabaseManager::recycle()` already purges the client handle (`db->purge()`) and fully stops the server, resetting server-side connections. The ceiling itself is simply wrong for this workload — an ephemeral, durability-off (`--skip-log-bin`, `innodb_doublewrite=OFF`), single-worker, test-only `mysqld` has no reason to cap at 151, a value tuned to protect long-lived production servers.
- **No consumer-side escape hatch:** `SnapshotConfig` exposes `connection`, `database`, `hash_paths`, `build_command`, `snapshot_dir`, `runtime_dir`, and the `mysqld` binary path — but **no knob for extra `mysqld` flags**. So the consuming app (YardPilot) cannot fix this from `config/warp.php`; the fix must land in the `rawphp/warp` package.

### Proposed direction (not yet captured — for Capture to refine)

1. Add a sane high `--max_connections` (e.g. `1000`) to the runtime `mysqld` flags in `src/Db/MysqldServer.php`.
2. Optionally expose a `warp.db.mysqld_args` config knob so consumers can append/override `mysqld` flags without a package release next time.
3. Downstream: after release (e.g. v0.3.1), YardPilot relocks `composer.json` to the new constraint.
