# Changelog

## Unreleased

- **Breaking (pre-release):** renamed the warm-mode env switch `WARP_WARM=1` → `WARP_MODE`, now accepting `1`, `on`, or `true` as engaged. Clean break — the legacy `WARP_WARM` variable is no longer read.
- S1 warm-worker PoC: warm application factory, reset manifest, hermeticity sentinel, `InteractsWithWarmApplication` trait, benchmark + parity harness.
- S1 gate: PASSED — Gate A 97.4× framework-tax reduction (53.57ms → 0.55ms), Gate B PARITY OK on 1,372 tests; see docs/reports/2026-07-04-s1-gate.md.
