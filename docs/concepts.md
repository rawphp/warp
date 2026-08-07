# Warp concepts

Mental model for classic mode, warm mode, sandboxes, resets, and parity. Read this when warm behaviour surprises you; for install steps see [Getting started](getting-started.md).

## Why it matters

Laravel’s full framework bootstrap is expensive when paid on **every** test. Warp keeps that cost on the process, then hands each test a **sandbox** derived from one warm base application. Classic mode stays the default so CI and local runs do not change until you set `WARP_MODE`.

## Classic vs warm

| Mode | When | What happens |
|------|------|----------------|
| **Classic** | `WARP_MODE` unset, or not `1` / `on` / `true` | Each test gets `createClassicApplication()` (full cold boot path your app already used). |
| **Warm** | `WARP_MODE` is `1`, `on`, or `true`, and the test is not isolated | Process boots once via your classic factory; each test receives a sandbox from `WarmApplicationFactory`. |
| **Isolated under warm** | `#[Isolated]` on the class, or Pest group `warp-isolated` | Fresh classic boot for that test; hermeticity check skipped. |

`WarpMode::enabled()` is the same rule the trait uses.

Parallel Pest workers are separate PHP processes: each worker that runs with `WARP_MODE` set warms **once for that worker**, not once for the whole machine.

## Sandbox (shallow clone)

Warm mode does **not** re-run a full framework boot per test. It builds a shallow clone of the base application with fresh container/config anchors. Container `bindings` / `instances` arrays are copied by value, so bindings a test adds die with that sandbox.

Some objects were already resolved at **boot** and are still shared by reference across sandboxes (router, events, db manager, and others). Those are handled by the **reset manifest**, not by hoping clone is deep.

The base **database manager** is shared on purpose so Laravel’s `RefreshDatabase` “migrate once + transaction per test” model still works.

## Reset manifest

`ResetManifest` is the declarative list of steps applied to every new sandbox: forget leaf services, repoint shared managers at the sandbox container, flush per-test methods (for example auth guards), and run custom closures.

`ResetManifest::default()` covers Laravel’s own stateful services Warp knows about. Your app overrides `warpResetManifest()` to chain extra `forget` / `repoint` / `flush` / `add` steps for packages and house services.

Details and tables: [Configuration](configuration.md).

## Hermeticity sentinel

After each warm sandbox test, Warp checks that the test did not leak **shared** state:

- Process env changes (Warp’s own `WARP_*` keys are ignored)
- Mutation of the warm **base** config through a shared reference
- Selected static probes (framework statics Warp tracks)

On violation the test **fails** with:

```text
[warp] hermeticity violation — this test leaked shared state: …. Fix the leak, or mark #[Isolated] / group("warp-isolated") if the test must change process state.
```

If the base was corrupted, Warp scraps it so the next test reboots pristine. That is the correctness backstop so warm mode stays honest instead of silently poisoning neighbours.

## Parity goal

Warm mode is designed for **byte-identical outcomes** to classic for the same suite: same passes, fails, and (where assertions allow) the same results. Speed is a benefit; parity is the bar.

Secondary effect seen on large suites: some timing assertions that fail under a slow classic run can pass under warm because the run is faster — that is a timing assertion issue, not a substitute for parity checks.

Optional harness: `bench/parity.sh` (classic vs warm junit-style comparison). Gate write-up: [S1 gate report](reports/2026-07-04-s1-gate.md).

## Snapshot DB (optional)

`WARP_DB=1` is a separate switch. It provisions a **golden** MySQL data directory (content-addressed under `.warp/snapshots/` by default), then gives each worker a copy-on-write clone and a throwaway `mysqld` on a private unix socket. Per-test DB isolation still uses your normal Laravel testing traits; the snapshot removes the heavy migrate/seed fixed cost per worker.

Requires MySQL 8 binaries and a `mysql` driver test connection. See [Configuration](configuration.md) and [Usage](usage.md).

## Timing capture and duration shards (optional)

`WARP_TIMINGS=1` plus `RawPHP\Warp\Timing\TimingExtension` in `phpunit.xml` records per-test durations under `.warp/timings/` (override with `WARP_TIMINGS_DIR`). `warp merge` compacts parallel pending batches; `warp shard k/n` packs files by recorded duration for CI; `warp timings` prints a short report.

## Key terms

| Term | Meaning |
|------|---------|
| Base application | The once-per-process Laravel app warm mode boots via your classic factory |
| Sandbox | Per-test clone + reset used as `$this->app` in warm mode |
| Classic factory | `createClassicApplication()` — your real cold boot |
| Reset manifest | Steps that fix shared boot singletons for each sandbox |
| Hermeticity | “This test did not leak shared process/base state” |
| Isolated test | Opt-out of warm sandbox for one class or Pest group |
| Golden snapshot | Cached migrated MySQL datadir used when `WARP_DB=1` |

## What to do next

- First install: [Getting started](getting-started.md)
- Commands and flags: [Usage and commands](usage.md)
- Knobs: [Configuration](configuration.md)
- Failures: [Troubleshooting](troubleshooting.md)
