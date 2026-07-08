# Warp

**A warm-worker test engine for Laravel + Pest.**

Warp boots your Laravel application **once per PHP process** and hands each test a
sandboxed shallow clone of it, instead of paying the full framework bootstrap
(~50 ms) on every single test. Classic mode stays the default; warm mode is opt-in
per run via `WARP_MODE=1` and is designed to produce **byte-identical outcomes** to
classic mode.

## Measured results

Measured on YardPilot's `tests/Feature/Quotes` suite (1,372 DB-backed tests):

| Gate | Result |
|------|--------|
| **A — per-test framework tax** | classic **53.57 ms/test** → warm **0.55 ms/test** (**97.4× reduction**) |
| **B — outcome parity** | `PARITY OK` — all 1,372 tests byte-identical classic vs warm; full suite **2.8× faster** warm |

Measured on a large suite (18,921 tests, 12 parallel processes):

| Metric | Classic | Warm |
|--------|---------|------|
| **Duration** | 802.08s | 314.23s |
| **Failures** | 1 (timing assertion, run too slow) | 0 |
| **Assertions** | 54,024 | 54,276 |

**2.55× speedup, 60.8% reduction, 487.9s saved per run.** The classic run's single failure was a
timing assertion that blew out under the slower run — warm mode's run had zero failures, which
illustrates a secondary benefit: warm mode **eliminates timing-based test flakiness caused by slow
cold-boot runs**.

See [`docs/reports/2026-07-04-s1-gate.md`](docs/reports/2026-07-04-s1-gate.md) for the full gate report
and [`docs/specs/2026-07-04-warp-test-engine-design.md`](docs/specs/2026-07-04-warp-test-engine-design.md) for the design.

---

## Requirements

- PHP **8.4+**
- Laravel **13**, Pest **4** (provided by the consuming app — Warp itself requires only `php`)

Warp's `composer.json` requires **only `php`**; every Illuminate class comes from the app
that installs it (or from `orchestra/testbench` in Warp's own suite). This guarantees zero
version conflicts when path-installed into a host app.

## Installation

```bash
composer require --dev rawphp/warp
```

## Usage

### 1. Add the trait to your base `TestCase`

Warp wires in through a single trait. Rename your existing `createApplication()` body to
`createClassicApplication()` — Warp calls it for the cold boot and, in warm mode, clones the
result per test.

```php
namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RawPHP\Warp\Concerns\InteractsWithWarmApplication;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithWarmApplication;

    /** Your original cold-boot application factory. */
    protected function createClassicApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }
}
```

That's the whole integration. With `WARP_MODE` unset, behaviour is **byte-identical** to
before — the trait falls straight through to `createClassicApplication()`.

### 2. Run the suite warm

```bash
# Classic (default, unchanged):
./vendor/bin/pest

# Warm mode — boot once, sandbox each test:
WARP_MODE=1 ./vendor/bin/pest
```

With Pest's `--parallel`, every paratest worker becomes warm automatically.

### 3. Customise the reset manifest (when needed)

A shallow `clone` of the application shares boot-resolved singletons between sandboxes. The
`ResetManifest` handles them declaratively; `ResetManifest::default()` covers Laravel's own
stateful services. Override `warpResetManifest()` to add app-specific ones:

```php
use RawPHP\Warp\ResetManifest;
use Spatie\Permission\PermissionRegistrar;

protected function warpResetManifest(): ResetManifest
{
    return ResetManifest::default()
        // Spatie's registrar caches permissions per instance — re-resolve fresh per test.
        ->forget(PermissionRegistrar::class);
}
```

`ResetManifest` primitives:

| Method | Purpose |
|--------|---------|
| `forget(string ...$ids)` | Drop a stateful leaf service so the sandbox re-resolves it fresh (e.g. `cache`, `session`, `view`). |
| `repoint(string $id, string $property)` | Rewrite a shared singleton's back-reference (`$container`/`$app`) to the sandbox without replacing the object (e.g. `router`, `events`, `db`). |
| `flush(string $id, string $method)` | Call a public reset method on a per-test service (e.g. `auth` → `forgetGuards`). |
| `add(Closure $step)` | A custom `fn ($sandbox, $base) => …` step for anything the primitives don't cover. |

### 4. Escape hatch — force a classic boot

Some tests genuinely can't share a warm base. Opt them out and Warp gives them a fresh cold
boot (and skips the hermeticity check):

```php
use RawPHP\Warp\Attributes\Isolated;

#[Isolated]
final class NeedsAFreshAppTest extends TestCase { /* … */ }
```

or per-test with a Pest group:

```php
it('needs isolation', function () {
    // …
})->group('warp-isolated');
```

## Snapshot DB provisioning (S2)

`WARP_DB=1` replaces the per-worker `migrate:fresh`/schema-load fixed cost with an
instant copy-on-write clone of a **golden datadir** — a fully migrated MySQL data
directory built once per schema change and cached under `.warp/snapshots/` (add
`.warp/` to your app's `.gitignore`). Each worker gets its own throwaway `mysqld`
on a private unix socket, so parallel workers can't collide by construction.

```bash
# Warm workers + snapshot DB:
WARP_MODE=1 WARP_DB=1 ./vendor/bin/pest --parallel
```

Requirements: MySQL 8 binaries on the machine (`mysqld` + `mysqladmin`; Homebrew,
apt, or point `WARP_DB_MYSQLD` at one) and a `mysql`-driver test connection.
Per-test isolation is unchanged — `RefreshDatabase` transaction-wraps as before;
the golden snapshot just makes its migrate step a no-op.

**Host wiring (required):** the golden build runs as a subprocess with
`DB_HOST`, `DB_PORT`, `DB_SOCKET`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`
injected into its env, pointed at that worker's throwaway `mysqld`. Your test
connection config must read the socket from that env var:

```php
// config/database.php — the connection named by config('warp.db.connection')
'mysql' => [
    // ...
    'unix_socket' => env('DB_SOCKET', ''),
],
```

Without it, the build subprocess falls back to whatever socket your driver
defaults to instead of the per-worker one warp provisioned — this can silently
target the wrong database in a single process, and fails outright
(`SQLSTATE[HY000] [2002] No such file or directory`) under `--parallel`.

Optional config, all under `config('warp.db')`:

| Key | Default | Purpose |
|-----|---------|---------|
| `connection` | `database.default` | Which connection to rewire (must be `mysql` driver) |
| `database` | the connection's `database` | Schema name baked into the snapshot |
| `hash_paths` | `database/migrations`, `database/seeders` | Files whose content keys the snapshot |
| `build_command` | `php artisan migrate --force` | Command that builds the schema (swap in `--seed` for a fixture universe) |
| `build_env` | `[]` | Extra env for the build command (wins over the injected `DB_*` vars) |
| `snapshot_dir` | `.warp/snapshots` | Golden snapshot cache (env: `WARP_DB_SNAPSHOT_DIR`) |
| `runtime_dir` | `/tmp/warp-db` | Clone + socket dir — keep it short, sockets live here (env: `WARP_DB_RUNTIME_DIR`) |
| `mysqld` | auto-discovered | Path to `mysqld` (env: `WARP_DB_MYSQLD`) |

Tests that must **commit** (DDL, multi-connection assertions) can call
`$this->warpRecycleDatabase()` for a fresh committed state via a sub-second
re-clone from golden.

## How it works

- **`WarmApplicationFactory`** boots the base app once per process and hands each test a
  shallow `clone` with fresh container/config anchors. The container's `bindings`/`instances`
  arrays are copied by value, so anything a test binds or resolves dies with its sandbox.
  Boot-resolved singletons shared by reference are handled by the manifest. The base's `db`
  manager is shared across sandboxes, keeping `RefreshDatabase`'s migrate-once + per-test
  transaction model intact.
- **`ResetManifest`** — the data-driven reset applied to every sandbox.
- **`HermeticitySentinel`** fingerprints env vars, the base config hash, and a static-probe
  registry after every test. If a test leaks shared state, the sentinel **fails that test**
  with attribution:

  ```
  [warp] hermeticity violation — this test leaked shared state: …
  ```

  A leak that corrupts the base scraps it, so the next test reboots pristine. This is the
  correctness backstop that lets warm mode stay honest.

## Public API

| Symbol | Description |
|--------|-------------|
| `RawPHP\Warp\Concerns\InteractsWithWarmApplication` | The trait host `TestCase`s use. |
| `RawPHP\Warp\WarpMode::enabled(): bool` | `true` when `WARP_MODE` is `1`, `on`, or `true`. |
| `RawPHP\Warp\Attributes\Isolated` | Class attribute forcing a classic boot. |
| `RawPHP\Warp\ResetManifest` | `default()` / `forget()` / `repoint()` / `flush()` / `add()`. |
| `RawPHP\Warp\WarmApplicationFactory` | `sandbox()` / `base()` / `bootCount()` / `checkHermeticity()` / `scrap()`. |
| `RawPHP\Warp\Sentinel\HermeticitySentinel` | Post-test leak detector. |
| `RawPHP\Warp\WarpMode::databaseEnabled(): bool` | `true` when `WARP_DB` is `1`, `on`, or `true`. |
| `RawPHP\Warp\Db\SnapshotDatabaseManager` | `apply()` / `recycle()` / `shutdown()` — per-worker snapshot DB provisioning. |

## Benchmarks

The gate harness lives under `bench/`:

```bash
# Gate A — per-test framework tax (classic vs warm marginal ms/test):
bench/warm-tax.sh /path/to/your/app

# Gate B — outcome parity on a suite (classic vs warm junit diff):
bench/parity.sh /path/to/your/app tests/Feature/YourSuite

# Gate S2 — DB provisioning fixed cost (classic migrate vs snapshot clone):
bench/db-provision.sh /path/to/your/app
```

## Development

```bash
composer install
./vendor/bin/pest        # package suite (Unit classic + Feature warm)
```

## Status

The warm-worker model is proven on real Laravel suites, from a few thousand tests up to
large parallel runs. See the [gate report](docs/reports/2026-07-04-s1-gate.md) for full
methodology and results.
