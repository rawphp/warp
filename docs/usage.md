# Usage and commands

Reference for running tests with Warp, environment switches, isolation, the `warp` CLI, optional snapshot DB and timings, and package benchmarks. Only flags and subcommands that exist in this repository are listed.

## Run tests (Pest)

Classic (default — `WARP_MODE` unset):

```bash
./vendor/bin/pest
```

Warm:

```bash
WARP_MODE=1 ./vendor/bin/pest
```

Warm + parallel (each Pest/paratest worker is its own process and warms independently):

```bash
WARP_MODE=1 ./vendor/bin/pest --parallel
```

Truthy values for mode flags: `1`, `on`, `true` (see `WarpMode` in source).

## Environment switches

| Variable | Effect when enabled (`1` / `on` / `true` unless noted) |
|----------|--------------------------------------------------------|
| `WARP_MODE` | Warm sandboxes via `InteractsWithWarmApplication` |
| `WARP_DB` | Snapshot MySQL provisioning (`SnapshotDatabaseManager::apply`) |
| `WARP_TIMINGS` | Record per-test durations (requires Timing extension registered) |
| `WARP_TIMINGS_DIR` | Directory for timing artifacts (default `.warp/timings`) |
| `WARP_DB_SNAPSHOT_DIR` | Golden snapshot cache directory |
| `WARP_DB_RUNTIME_DIR` | Clone + socket directory (keep short; default `/tmp/warp-db`) |
| `WARP_DB_MYSQLD` | Path to `mysqld` if not on `PATH` |
| `WARP_STRICT_ROOT` | Any non-empty value other than `0`: hard-fail `warp shard` on timings root mismatch even when relative keys still match |

Diagnostic (development): `WARP_TRACE_BASE_RESOLVE`, `WARP_SENTINEL_BASE_INSTANCES` — optional probes in the warm factory; not required for normal use.

Add `.warp/` to the **application** `.gitignore` if you use snapshots or timings locally.

## Isolation (escape hatch)

Force a classic boot for a class:

```php
use RawPHP\Warp\Attributes\Isolated;

#[Isolated]
final class NeedsAFreshAppTest extends TestCase
{
}
```

Or Pest:

```php
it('needs isolation', function () {
    // …
})->group('warp-isolated');
```

## Snapshot DB runs

Requirements: MySQL 8 (`mysqld`, `mysqladmin`), test connection driver `mysql`, and host config that honours `DB_SOCKET` (see [Configuration](configuration.md)).

```bash
WARP_MODE=1 WARP_DB=1 ./vendor/bin/pest --parallel
```

Tests that must **commit** (DDL, multi-connection) can call:

```php
$this->warpRecycleDatabase();
```

That re-clones from the golden snapshot for a fresh committed state.

## Timing capture + duration-balanced sharding

### 1. Register the extension once

In the app’s `phpunit.xml` (or `.dist`):

```xml
<extensions>
    <bootstrap class="RawPHP\Warp\Timing\TimingExtension"/>
</extensions>
```

No-op unless `WARP_TIMINGS` is enabled.

### 2. Record on full runs

```bash
WARP_TIMINGS=1 ./vendor/bin/pest --parallel
./vendor/bin/warp merge
```

Parallel workers write pending batches under the timings dir; `warp merge` folds them into `timings.json`. Prefer **full** suite recording — a `--filter` run replaces a file’s entries with only the filtered subset.

Canonical timing keys are rooted at the directory of the `phpunit.xml` actually used (`--configuration` or auto-discovered), not necessarily the shell cwd. Stamp/root behaviour is implemented in the timing store and shard command.

### 3. Inspect

```bash
./vendor/bin/warp timings
# optional:
./vendor/bin/warp timings --timings-dir=DIR
```

### 4. Shard for CI

```bash
./vendor/bin/warp shard 3/8
./vendor/bin/warp shard 3/8 --configuration=path/to/phpunit.xml
./vendor/bin/warp shard 3/8 tests/Feature --suffix=Test.php
./vendor/bin/warp shard 3/8 --timings-dir=DIR
```

Usage line (from CLI):

```text
warp shard <index>/<total> [paths...] [--timings-dir=DIR] [--suffix=Test.php] [--configuration=FILE]
```

- No path args: discover from `phpunit.xml` / `phpunit.xml.dist` testsuites (directories, files, suffix/prefix/excludes).
- No PHPUnit config: fallback discovery under `tests/` with `Test.php` suffix (`--suffix=` changes fallback only).
- Stdout: newline-delimited file list (paths relative to the canonical root) for command substitution.
- No usable timings: count-balanced packing with a warning on stderr; stdout stays clean for `$(…)`.

Exit codes:

| Exit | Meaning | CI consequence |
|------|---------|----------------|
| 0 | Shard has files | Run Pest with those files |
| 2 | Usage, discovery, timings, zero files, or other error | Fail the job |
| 3 | Empty shard (more shards than files) | Skip Pest; do not pass an empty list |

Example guard under `sh -e`:

```bash
set +e
FILES=$(./vendor/bin/warp shard "${CI_NODE_INDEX}/${CI_NODE_TOTAL}")
rc=$?
set -e

if [ "$rc" -eq 3 ]; then
    echo "[warp] shard is empty; skipping pest"
    exit 0
fi

if [ "$rc" -ne 0 ]; then
    exit "$rc"
fi

./vendor/bin/pest $FILES
```

Root mismatch (artifact absolute root ≠ shard-time root):

- If relative keys still match discovered files: **use timings** and warn (portable baseline). Set `WARP_STRICT_ROOT=1` to make that a hard error instead.
- If no keys match: degrade to count-balanced and warn.

`warp shard` and `warp timings` are read-only against the artifact (overlay pending in memory). `warp merge` is the write/compaction step.

### CLI summary

```text
warp - test engine CLI
usage:
  warp merge [--timings-dir=DIR]
  warp shard <index>/<total> [paths...] [--timings-dir=DIR] [--suffix=Test.php] [--configuration=FILE]
  warp timings [--timings-dir=DIR]
```

Binary path when installed in an app: `./vendor/bin/warp` (Composer `"bin": ["bin/warp"]`).

## Benchmarks (package tree)

From a Warp checkout, against a host app path:

```bash
bench/warm-tax.sh /path/to/your/app
bench/parity.sh /path/to/your/app tests/Feature/YourSuite
bench/db-provision.sh /path/to/your/app
bench/shard-spread.sh /path/to/your/app 8
```

## Develop Warp itself

```bash
composer install
./vendor/bin/pest
```

## Related

- [Getting started](getting-started.md)
- [Configuration](configuration.md)
- [Troubleshooting](troubleshooting.md)
- [Concepts](concepts.md)
