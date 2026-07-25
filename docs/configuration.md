# Configuration

Knobs Warp reads from the environment, your TestCase, Laravel config, and PHPUnit. There is no separate Composer “publish config” step in this package; set values in the host app as shown.

## Feature flags (process environment)

Resolved by `RawPHP\Warp\WarpMode` unless noted.

| Variable | Accepted “on” values | Purpose |
|----------|----------------------|---------|
| `WARP_MODE` | `1`, `on`, `true` | Enable warm sandboxes |
| `WARP_DB` | `1`, `on`, `true` | Enable snapshot DB provisioning |
| `WARP_TIMINGS` | `1`, `on`, `true` | Enable timing extension recording |

Other env:

| Variable | Default / notes |
|----------|-----------------|
| `WARP_TIMINGS_DIR` | `.warp/timings` if unset or empty |
| `WARP_DB_SNAPSHOT_DIR` | else `config('warp.db.snapshot_dir')`, else `{app}/.warp/snapshots` |
| `WARP_DB_RUNTIME_DIR` | else `config('warp.db.runtime_dir')`, else `/tmp/warp-db` |
| `WARP_DB_MYSQLD` | else `config('warp.db.mysqld')`, else PATH discovery |
| `WARP_STRICT_ROOT` | Off unless set to a non-empty value other than `0` |

Env overrides for DB paths win over Laravel config when the env var is non-empty (see `SnapshotConfig::fromApplication`).

## TestCase hooks

Provided by `RawPHP\Warp\Concerns\InteractsWithWarmApplication`:

| Member | Role |
|--------|------|
| `createClassicApplication(): Application` | **Abstract** — your cold boot (required) |
| `warpResetManifest(): ResetManifest` | Override to extend `ResetManifest::default()` |
| `warpRecycleDatabase(): void` | Re-clone DB from golden snapshot (`WARP_DB`) |
| `usingWarmSandbox(): bool` | Whether this test instance is on a warm sandbox |
| `createApplication(): Application` | Trait implementation — do not shadow unless replacing Warp |

Isolation detection: class attribute `RawPHP\Warp\Attributes\Isolated`, or PHPUnit/Pest group name `warp-isolated` via `groups()`.

## ResetManifest API

```php
use RawPHP\Warp\ResetManifest;

protected function warpResetManifest(): ResetManifest
{
    return ResetManifest::default()
        ->forget('some.service', Another::class)
        ->repoint('some.manager', 'app')
        ->flush('auth', 'forgetGuards')
        ->add(function ($sandbox, $base): void {
            // custom per-sandbox step
        });
}
```

| Method | Purpose |
|--------|---------|
| `default()` | Laravel-oriented built-in steps (cache/session/view forgets, router/events/db/auth repoints, gate/pagination/mail/url fixes, and more) |
| `forget(string ...$ids)` | Drop instances so the sandbox re-resolves them |
| `repoint(string $id, string $property)` | Point a shared singleton’s `$property` (e.g. `app` / `container`) at the sandbox |
| `flush(string $id, string $method)` | Call a public reset method on a resolved service |
| `add(Closure $step)` | `function (Application $sandbox, Application $base): void` |
| `apply(Application $sandbox, Application $base)` | Runs the manifest (used internally by the factory) |

Use `forget` for stateful leaf services; `repoint` when other boot objects must keep the same instance but talk to the sandbox container; `flush` for methods like `forgetGuards`; `add` when none of the primitives fit.

## Snapshot DB (`config('warp.db.*')`)

Read in `SnapshotConfig::fromApplication`. The package does not ship a Laravel service provider; define keys under `warp.db` in the host app (for example `config/warp.php` returning a `db` array, or any config merge your app already uses).

| Key | Default | Purpose |
|-----|---------|---------|
| `connection` | `database.default` | Connection name to rewire; **driver must be `mysql`** |
| `database` | that connection’s `database` | Schema name baked into the snapshot |
| `hash_paths` | `{base}/database/migrations`, `{base}/database/seeders` | Paths whose contents key the golden snapshot |
| `build_command` | `[PHP_BINARY, 'artisan', 'migrate', '--force']` | Command that builds schema (array form in config) |
| `build_env` | `[]` | Extra env for the build; wins over injected `DB_*` |
| `snapshot_dir` | `.warp/snapshots` under app base | Golden cache (overridable by `WARP_DB_SNAPSHOT_DIR`) |
| `runtime_dir` | `/tmp/warp-db` | Clones and sockets (overridable by `WARP_DB_RUNTIME_DIR`) |
| `mysqld` | auto-discovered | `mysqld` binary (overridable by `WARP_DB_MYSQLD`) |

### Required host wiring for sockets

The golden build runs as a subprocess with `DB_HOST`, `DB_PORT`, `DB_SOCKET`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` injected toward the worker’s throwaway `mysqld`. The connection named by `warp.db.connection` must read the socket from env:

```php
// config/database.php — connection used for tests / warp.db.connection
'mysql' => [
    // ...
    'unix_socket' => env('DB_SOCKET', ''),
],
```

Without this, the build can target the wrong socket (or fail under `--parallel` with `SQLSTATE[HY000] [2002] No such file or directory`).

## Timing extension (PHPUnit)

```xml
<extensions>
    <bootstrap class="RawPHP\Warp\Timing\TimingExtension"/>
</extensions>
```

Recording engages only when `WARP_TIMINGS` is on. Artifact layout lives under `WARP_TIMINGS_DIR` / `.warp/timings` (`timings.json` + pending batches). CLI flags `--timings-dir=DIR` override per `warp` invocation.

## Public symbols (quick index)

| Symbol | Role |
|--------|------|
| `RawPHP\Warp\Concerns\InteractsWithWarmApplication` | Host TestCase trait |
| `RawPHP\Warp\WarpMode` | `enabled()` / `databaseEnabled()` / `timingsEnabled()` |
| `RawPHP\Warp\Attributes\Isolated` | Class-level classic force |
| `RawPHP\Warp\ResetManifest` | Sandbox reset DSL |
| `RawPHP\Warp\WarmApplicationFactory` | `sandbox` / hermeticity / scrap (advanced) |
| `RawPHP\Warp\Sentinel\HermeticitySentinel` | Leak detector |
| `RawPHP\Warp\Db\SnapshotDatabaseManager` | `apply` / `recycle` / `shutdown` |
| `RawPHP\Warp\Timing\TimingExtension` | PHPUnit extension |
| `RawPHP\Warp\Timing\TimingStore` | Timings artifact IO |
| `RawPHP\Warp\Shard\DurationBalancedSharder` | LPT packing |
| `bin/warp` | `merge` / `shard` / `timings` |

## Related

- [Usage and commands](usage.md)
- [Concepts](concepts.md)
- [Troubleshooting](troubleshooting.md)
