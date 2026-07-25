# Get started with Warp

Install Warp in a Laravel app, wire one trait, keep **classic** behaviour by default, then run the same suite with **warm** mode when you want a faster process boot.

## Before you start

- PHP **8.4+**
- A Laravel app that already runs tests with **Pest** (Warp’s own package suite uses Pest 4; the library requires only PHP plus PHPUnit components — Illuminate comes from your app)
- Ability to change your base `TestCase` (or equivalent)

Time: a few minutes for install and trait wiring; suite runtime depends on your project.

Related: [Concepts](concepts.md) · [Usage and commands](usage.md)

## Steps

### 1. Install the package

From your application root:

```bash
composer require --dev rawphp/warp
```

### 2. Wire the trait on your base TestCase

Warp replaces the usual `createApplication()` entry point. Keep your cold-boot body, rename it to `createClassicApplication()`, and use `InteractsWithWarmApplication`.

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

Do not leave a separate `createApplication()` on the class that shadows the trait unless you intentionally replace Warp’s implementation.

### 3. Run classic (default)

With `WARP_MODE` unset (or not `1` / `on` / `true`), the trait calls `createClassicApplication()` every time — same shape as a normal Laravel test boot.

```bash
./vendor/bin/pest
```

You should see your usual pass/fail output. Treat this as the baseline.

### 4. Run warm

```bash
WARP_MODE=1 ./vendor/bin/pest
```

Warm boots the app **once per PHP process** and gives each test a sandboxed shallow clone. With Pest `--parallel`, each worker process warms independently.

```bash
WARP_MODE=1 ./vendor/bin/pest --parallel
```

### 5. (Optional) Add app-specific resets

If warm mode fails only for services that hold process-wide state (for example a package registrar), extend the reset manifest on your TestCase:

```php
use RawPHP\Warp\ResetManifest;
use Spatie\Permission\PermissionRegistrar;

protected function warpResetManifest(): ResetManifest
{
    return ResetManifest::default()
        ->forget(PermissionRegistrar::class);
}
```

See [Configuration](configuration.md) for `forget` / `repoint` / `flush` / `add`.

### 6. (Optional) Force classic for hard cases

Class attribute:

```php
use RawPHP\Warp\Attributes\Isolated;

#[Isolated]
final class NeedsAFreshAppTest extends TestCase
{
    // …
}
```

Or Pest group:

```php
it('needs isolation', function () {
    // …
})->group('warp-isolated');
```

Isolated tests get a fresh classic boot and skip the warm hermeticity check.

## How you know it worked

| Check | What you should see |
|-------|---------------------|
| Classic after trait | Same green/red outcomes as before the trait (no `WARP_MODE`) |
| Warm | Suite completes; failures should match classic aside from intentional isolation or known timing-sensitive assertions |
| Hermeticity | No `[warp] hermeticity violation — this test leaked shared state: …` failures |
| Optional parity | Same tests pass/fail under classic and `WARP_MODE=1` (package ships `bench/parity.sh` for junit-style comparison) |

Measured speedups on real suites are recorded in [docs/reports](reports/2026-07-04-s1-gate.md); your numbers will differ.

## If something goes wrong

| Symptom | Likely cause | What to do |
|---------|--------------|------------|
| Trait / method errors about `createApplication` | Old `createApplication()` still defined and conflicting, or classic factory not renamed | Keep only `createClassicApplication()` + the trait |
| Warm fails, classic passes | Shared singleton or package state not reset | Extend `warpResetManifest()`; see [Troubleshooting](troubleshooting.md) |
| `[warp] hermeticity violation` | Test leaked env or mutated shared base config | Fix the leak, or mark `#[Isolated]` / `warp-isolated` if the test must change process state |
| Composer version conflict fear | Warp only requires `php` + PHPUnit file-iterator/phpunit for the package surface | Illuminate comes from the host app; path-install is designed to avoid Illuminate pin fights |

## Related

- [Concepts](concepts.md) — classic vs warm, sandbox, sentinel
- [Usage and commands](usage.md) — env flags, CLI, DB snapshots, sharding
- [Configuration](configuration.md) — manifests, `warp.db`, timings dirs
- [Troubleshooting](troubleshooting.md) — fuller symptom table
