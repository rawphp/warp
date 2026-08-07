# Troubleshoot Warp

Symptom-first fixes for install, warm mode, hermeticity, snapshot DB, and timing/sharding. Prefer fixing shared state over blanket isolation when the suite should stay warm.

## Quick checks

1. Confirm the trait is on the base TestCase and cold boot lives in `createClassicApplication()` only.
2. Confirm whether `WARP_MODE` / `WARP_DB` / `WARP_TIMINGS` are set in the failing command (`1`, `on`, or `true`).
3. Re-run the same filter **without** `WARP_MODE` — classic vs warm splits many bugs immediately.
4. For DB issues, confirm driver is `mysql` and `unix_socket` => `env('DB_SOCKET', '')` on the test connection.
5. For shard/CI issues, confirm the same Warp version and the same timings artifact on every node; run `warp merge` after recording.

## Symptoms

### Suite behaves differently only when `WARP_MODE=1`

**Cause:** A boot-resolved singleton or package service shares mutable state across sandboxes, or a test mutates process globals the sentinel does not fully cover the way you expect.

**Fix:**

1. Extend `warpResetManifest()` with `forget` / `repoint` / `flush` / `add` for the offending service ([Configuration](configuration.md)).
2. If the test *must* own a full cold boot, mark `#[Isolated]` or `->group('warp-isolated')`.
3. Compare with classic on the same filter; fix assertions that assumed a slower wall clock if that is the only delta.

### `[warp] hermeticity violation — this test leaked shared state: …`

**Cause:** The test changed process env (non-`WARP_*`), mutated base config through a shared reference, or tripped a static probe the sentinel tracks. Env leaks are restored after detection so neighbours are less poisoned; base corruption scraps the warm base.

**Fix:**

1. Read the leak description in the failure message and stop mutating that shared surface — or restore it in the test teardown before Warp’s check.
2. Avoid writing through objects that still point at the base application.
3. Use isolation only when the test’s job is inherently process-global.

### Warm fails with Artisan / `migrate:fresh` “command does not exist”

**Cause:** Console bootstrappers or Artisan cache tied to a torn-down sandbox (framework teardown clears process statics; warm must restore them). Current Warp reset/factory code addresses the common cases; an outdated Warp version or a custom console kernel path may still break.

**Fix:** Upgrade Warp; ensure you are not bypassing the trait; file a minimal repro if a custom kernel still fails after upgrade.

### Policy / auth checks always 403 in warm only

**Cause:** Gate user resolver closed over the base app’s auth (handled in `ResetManifest::default()` for stock Gate). Custom auth stacks may need an extra `add()` step.

**Fix:** Confirm default manifest is not replaced entirely — chain from `ResetManifest::default()`. Add a custom step that rebinds your gate/auth resolvers to the sandbox.

### Pagination always returns page 1 in warm

**Cause:** Pagination resolvers registered once against the base request.

**Fix:** Stock manifest rebinds via `PaginationState::resolveUsing($sandbox)` when that class exists. If you replaced the default manifest, restore that behaviour or upgrade Warp.

### Session / redirect / signed URL weirdness across tests in one worker

**Cause:** URL generator session/key resolvers still bound to the base; session state bleeds.

**Fix:** Use `ResetManifest::default()` (includes url extender fixes). Forget `session` / `session.store` if you customised the manifest and dropped those forgets.

### `WARP_DB` errors: needs a mysql connection

**Cause:** `SnapshotConfig` requires driver `mysql` on the configured connection.

**Fix:** Point `warp.db.connection` at a mysql connection, or change the test connection driver. SQLite is not supported for snapshot provisioning.

### `SQLSTATE[HY000] [2002] No such file or directory` under parallel + `WARP_DB`

**Cause:** Build or tests not using the per-worker unix socket; `DB_SOCKET` not wired in `config/database.php`.

**Fix:**

```php
'unix_socket' => env('DB_SOCKET', ''),
```

on the connection Warp rewires. Do not hardcode a host socket path for that connection during snapshot runs.

### `mysqld not found`

**Cause:** MySQL 8 server binary missing from `PATH`.

**Fix:** Install MySQL 8 or set `WARP_DB_MYSQLD` / `config('warp.db.mysqld')` to the binary.

### `SQLSTATE[08004] [1040] Too many connections` on throwaway mysqld

**Cause:** Older Warp versions used default `max_connections` on ephemeral mysqld; long warm workers could exhaust it.

**Fix:** Use Warp **≥ 0.3.1** (raises ephemeral `max_connections`). Recycle connections in tests if you open many without closing.

### Snapshot rebuilds every run / never hits cache

**Cause:** `hash_paths` content changed (migrations/seeders), or snapshot dir not persisted between CI jobs.

**Fix:** Cache `.warp/snapshots` (or `WARP_DB_SNAPSHOT_DIR`) appropriately; keep `hash_paths` accurate; avoid writing into hashed paths during tests.

### Timings: `no timings recorded yet`

**Cause:** Extension missing, or `WARP_TIMINGS` not set during the run.

**Fix:** Register `RawPHP\Warp\Timing\TimingExtension` in `phpunit.xml`; run with `WARP_TIMINGS=1`; then `warp merge` if parallel.

### `warp shard` exit 2 / empty discovery

**Cause:** Bad usage, no files discovered, or strict root failure.

**Fix:** Check stderr. Ensure `phpunit.xml` testsuites or explicit paths are correct. For root mismatch with matching relative keys, default behaviour is to **use** timings and warn; only `WARP_STRICT_ROOT` forces exit 2 in that case. Foreign artifacts with zero key overlap degrade to count-balanced.

### `warp shard` exit 3

**Cause:** More shards than test files — this shard is empty.

**Fix:** Skip Pest for that shard (see CI guard in [Usage](usage.md)); do not treat as a product failure.

### Shard plan differs across CI nodes

**Cause:** Different Warp versions, different timings artifacts, or different `--configuration` / discovery roots.

**Fix:** Same Warp version and same restored `.warp/timings` (after `merge`) on every node; same configuration path convention.

### Composer install worries about Illuminate versions

**Cause:** Misreading Warp as pinning Laravel.

**Fix:** Warp’s runtime `require` is PHP + PHPUnit components; Illuminate types come from the host app (or Testbench in Warp’s own suite).

### Trait integration: wrong application factory

**Cause:** Trait added without `createClassicApplication()`, or the class still defines `createApplication()` next to the trait (collision), or classic hook does not call the real cold boot.

**Fix (current Laravel):** 

```php
use InteractsWithWarmApplication;

protected function createClassicApplication(): Application
{
    return parent::createApplication();
}
```

Do not re-declare `createApplication()` on the class. If you had a custom factory or `CreatesApplication`, move/alias it into `createClassicApplication()` only — see [Getting started](getting-started.md).

## Still stuck

1. Run the smallest failing filter classic vs warm and capture both outputs.
2. Check [Concepts](concepts.md) for sandbox vs shared singleton expectations.
3. Skim gate reports under `docs/reports/` for methodology, not as a substitute for your suite’s parity.
4. Open an issue on the upstream repository with Warp version, Laravel/Pest versions, and a minimal repro.

## Related

- [Getting started](getting-started.md)
- [Usage and commands](usage.md)
- [Configuration](configuration.md)
- [Concepts](concepts.md)
