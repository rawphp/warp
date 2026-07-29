# Warp documentation

User guides for **rawphp/warp** — a warm-worker test engine for Laravel + Pest.

Use these pages when you want to install Warp, run tests warm, configure resets or snapshot DB, shard CI by duration, or fix a failure. Design notes and gate measurements live under **specs** and **reports**; they are not day-to-day operator docs.

## Start here

| Goal | Page |
|------|------|
| Install and run classic, then warm | [Getting started](getting-started.md) |
| Understand classic vs warm, sandboxes, resets, parity | [Concepts](concepts.md) |
| Run Pest, env flags, `bin/warp`, isolation, benchmarks | [Usage and commands](usage.md) |
| Env vars, `ResetManifest`, `warp.db.*`, timing paths | [Configuration](configuration.md) |
| Symptom → cause → fix | [Troubleshooting](troubleshooting.md) |

## Suggested path

1. [Getting started](getting-started.md) — wire the trait and prove classic + warm on your suite.
2. [Concepts](concepts.md) — if warm failures or shared state need a mental model.
3. [Configuration](configuration.md) — app-specific reset steps, `WARP_DB`, timings.
4. [Usage and commands](usage.md) — CI sharding and day-to-day commands.
5. [Troubleshooting](troubleshooting.md) — when a run fails or diverges from classic.

## Maintainers

| Goal | Page |
|------|------|
| Tag a release, CHANGELOG gate, Packagist | [Releases](RELEASES.md) |

## Design and measurement (not user guides)

| Artifact | What it is |
|----------|------------|
| [specs/2026-07-04-warp-test-engine-design.md](specs/2026-07-04-warp-test-engine-design.md) | Internal design |
| [reports/2026-07-04-s1-gate.md](reports/2026-07-04-s1-gate.md) | S1 warm-worker gate (framework tax + parity) |
| [reports/2026-07-07-s2-gate.md](reports/2026-07-07-s2-gate.md) | S2 snapshot DB gate |

Package overview and a short install path also live in the repository [README](../README.md).
