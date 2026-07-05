# Changelog

## Unreleased

## 0.1.0 - 2026-07-05

First public release.

- **Breaking (pre-release):** vendor-prefixed the PHP namespace root from bare `Warp\` to `RawPHP\Warp\` (and `Warp\Tests\` → `RawPHP\Warp\Tests\`) to match PSR-4 convention and avoid collisions ahead of public release. Update all `use Warp\…` imports to `use RawPHP\Warp\…`. The Composer package name `rawphp/warp` and the `WarpMode` class / `WARP_MODE` env var are unchanged.
- **Breaking (pre-release):** renamed the warm-mode env switch `WARP_WARM=1` → `WARP_MODE`, now accepting `1`, `on`, or `true` as engaged. Clean break — the legacy `WARP_WARM` variable is no longer read.
- S1 warm-worker PoC: warm application factory, reset manifest, hermeticity sentinel, `InteractsWithWarmApplication` trait, benchmark + parity harness.
- S1 gate: PASSED — Gate A 97.4× framework-tax reduction (53.57ms → 0.55ms), Gate B PARITY OK on 1,372 tests; see docs/reports/2026-07-04-s1-gate.md.
