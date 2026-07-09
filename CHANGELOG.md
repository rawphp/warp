# Changelog

## Unreleased

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
