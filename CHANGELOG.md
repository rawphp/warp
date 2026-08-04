# Changelog

## Unreleased

## 0.6.0 - 2026-08-05

### Changed

- **Architecture cleanup (pass 3)** — host-facing surface unchanged. Internals:
  `Support\ProcessProbe` + `Db\DeadWorkerSweep` for orphan worker reaping;
  `Warm\SandboxBuilder` for per-test clone assembly; `Timing\ExtensionRegistrar`
  owns PHPUnit subscriber wiring so `TimingExtension` is bootstrap-only;
  `TimingCollector` duration DRY; pure `Shard\ShardDiscovery` for shard file
  list + timing-key root. Prefer documented public symbols over reflecting
  `@internal` class names.

- **Architecture cleanup (pass 2)** — host-facing surface unchanged. Internals:
  `FileLock::withLockOr` for soft-open timings reads; pure `Timing\ShardTotals`
  for shard root-mismatch policy; `Timing\PendingBatches` owns pending/
  scan+fold so `TimingStore` is lifecycle-only. Prefer documented public symbols
  over reflecting `@internal` class names.

## 0.5.0 - 2026-08-04

### Changed

- **Architecture cleanup (warm split / pure timing core)** — host-facing
  surface unchanged; package internals reorganized and marked `@internal`
  (`WarmSession`, `BootSnapshot`, `DefaultResetSteps`, `ObjectAccess`,
  `TimingsMerge`). Prefer the public symbols table in `docs/configuration.md`
  / README over reflecting internal class names.

### Removed

- **`TimingStore::aggregate()`** — pure per-file totals math moved to
  `Timing\TimingsMerge::aggregate()` (internal). Hosts and tooling should use
  `TimingStore::fileTotals()` (still public; delegates to the pure core) or
  `TimingStore::load()` for the raw test map. Direct `TimingStore::aggregate()`
  call sites break.
- **`RawPHP\Warp\Db\Dirs`** — BC class alias removed. The helper lives only at
  `RawPHP\Warp\Support\Dirs` and was never a documented public API; update any
  out-of-tree `use RawPHP\Warp\Db\Dirs` imports.

## 0.4.0 - 2026-07-29

### Fixed

- **WARP_DB worker datadir teardown races** (`Dirs::delete` + recycle/shutdown):
  under parallel Pest with `WARP_MODE` + `WARP_DB`, InnoDB temp files
  (`#ib_redo*_tmp` and similar) could disappear or reappear mid-walk during
  worker datadir cleanup, so bare `unlink`/`rmdir` in `Dirs.php` became
  Laravel testing `ErrorException`s on otherwise green bystander tests.
  `Dirs::delete` is now race-safe (ENOENT ignored mid-walk; bounded retry on
  "directory not empty"; non-ENOENT failures still surface). After
  `mysqld` stop in `recycle()`/`shutdown()`, a short settle runs before
  datadir delete so the tree is quieter before cleanup.

### Changed

- **Release process** is now scripted: `scripts/release.sh` (and
  `composer release` / `composer release:dry`) runs CHANGELOG + quality gates,
  creates an annotated semver tag, and pushes **tag only** for Packagist.
  See `docs/RELEASES.md` and the project `/release` skill.

## 0.3.2 - 2026-07-11

### Changed

- **`warp shard` root-mismatch policy** (`ShardCommand`): when the recorded
  timings `root` differs from the shard-time canonical root but the recorded
  **relative** keys still match discovered files, warp now **uses the timings**
  (emitting a `timings root differs … using them` warning) instead of hard-
  failing with exit 2. A differing absolute root is metadata only — relative
  keys that match are portable — so this unblocks the legitimate committed/
  shared-baseline workflow: a `.warp/timings` artifact recorded on one machine
  (e.g. `/Users/…`) and sharded on a differently-rooted clone or CI runner
  (e.g. `/home/runner/…`). The prior behaviour treated this as a "real
  misconfiguration" and failed every shard. The "no recorded key matches"
  path still degrades to count-balanced (unchanged).

### Added

- **`WARP_STRICT_ROOT`** env opt-in: set to any non-empty value (other than
  `0`) to restore the old fail-loudly behaviour — a stored/canonical root
  mismatch becomes a hard error (exit 2) even when relative keys still match.

## 0.3.1 - 2026-07-11

### Fixed

- **Throwaway `mysqld` connection exhaustion** (`WARP_DB`): the per-worker
  snapshot `mysqld` booted with `--no-defaults` and no `--max_connections`, so
  it fell back to MySQL's stock 151 default. Under warm-worker mode a single
  worker process stays alive across a whole suite and exhausted that ceiling
  with `SQLSTATE[08004] [1040] Too many connections`. Raised to `1000` for the
  ephemeral, durability-off test instance. Runtime start flag only — the golden
  snapshot datadir and `SnapshotKey` are unchanged.

## 0.3.0 - 2026-07-10

### Added

- **S3 — per-test timing capture + duration-balanced CI sharding**: a PHPUnit
  extension (`RawPHP\Warp\Timing\TimingExtension`, engaged via `WARP_TIMINGS=1`)
  records every test's duration with file attribution into a portable
  `.warp/timings` artifact; the new `warp` CLI packs CI shards to equal
  duration via deterministic LPT (`warp shard <k>/<n>`), collapsing
  count-based shard spread to the mean. `warp timings` prints artifact stats.
- `WarpMode::timingsEnabled()`, `WARP_TIMINGS_DIR` env override, `bin/warp`
  composer binary.
- `bench/shard-spread.sh` — S3 gate harness (count-based vs duration-balanced
  spread report from recorded timings).

### Changed

- `warp shard` now exits 2 when discovery finds zero test files, keeping that
  error distinct from exit 3 legitimately empty shards.

## 0.2.0 - 2026-07-08

### Added

- **S2 — golden-snapshot DB provisioning** (`WARP_DB=1`): per-worker copy-on-write
  clones of a content-addressed golden MySQL datadir, served by throwaway `mysqld`
  instances on private unix sockets. Replaces the ~14.4s per-worker migrate/seed
  fixed cost with a ~2s clone+boot; parallel-safe by construction.
- `WarpMode::databaseEnabled()`, `SnapshotDatabaseManager` (`apply`/`recycle`/`shutdown`),
  `warpRecycleDatabase()` test helper, `warp.db.*` config surface,
  `WARP_DB_MYSQLD` / `WARP_DB_SNAPSHOT_DIR` / `WARP_DB_RUNTIME_DIR` env overrides.
- `bench/db-provision.sh` — S2 gate harness (fixed-cost delta + 4-way multi-mysqld PoC).

## 0.1.0 - 2026-07-05

First public release.

- **Breaking (pre-release):** vendor-prefixed the PHP namespace root from bare `Warp\` to `RawPHP\Warp\` (and `Warp\Tests\` → `RawPHP\Warp\Tests\`) to match PSR-4 convention and avoid collisions ahead of public release. Update all `use Warp\…` imports to `use RawPHP\Warp\…`. The Composer package name `rawphp/warp` and the `WarpMode` class / `WARP_MODE` env var are unchanged.
- **Breaking (pre-release):** renamed the warm-mode env switch `WARP_WARM=1` → `WARP_MODE`, now accepting `1`, `on`, or `true` as engaged. Clean break — the legacy `WARP_WARM` variable is no longer read.
- S1 warm-worker PoC: warm application factory, reset manifest, hermeticity sentinel, `InteractsWithWarmApplication` trait, benchmark + parity harness.
- S1 gate: PASSED — Gate A 97.4× framework-tax reduction (53.57ms → 0.55ms), Gate B PARITY OK on 1,372 tests; see docs/reports/2026-07-04-s1-gate.md.
