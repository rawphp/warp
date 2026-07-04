# Warp — Test Engine for 100k DB-Backed Feature Tests

**Status:** Approved design — S1 PoC gate PASSED (see docs/reports/2026-07-04-s1-gate.md)
**Date:** 2026-07-04
**Working name:** Warp (provisional)
**First testbed:** YardPilot (~30k Pest tests, MySQL 8, 1,112 test files)

## Problem

Run 100,000 Laravel/Pest feature tests against real MySQL, fast and reliably, both in CI and on a laptop. Conventional levers (sharding, bigger runners) only buy parallelism and scale cost linearly with suite size. At 100k tests a 0.01% flake rate means every run is red, so reliability must be structural, not disciplined.

**Constraints (decided during brainstorm):**

- Real MySQL semantics for every test, every run — no engine swapping (no SQLite tiers).
- Both CI and local must be fast.
- Full-sweep changes to existing tests are acceptable, but the core engine must work infra-only (unmodified tests).
- General-purpose tool, not a YardPilot one-off. Change-tracking/selection may be part of the solution.

## Measured physics (YardPilot, M-series Mac, 12 cores, 2026-07-04)

Absolute numbers were measured while another parallel test run loaded the machine, so they are ~20–30% conservative. Structure over precision:

| Measurement | Result | Implication |
|---|---|---|
| APFS `clonefile` copy of live 3.7GB / 9,870-file MySQL datadir | 1.65s, zero extra disk | Per-worker CoW DB provisioning is effectively free on macOS |
| MySQL commit cost, `innodb_flush_log_at_trx_commit=1` vs `0` | 0.42ms vs 0.37ms per test-like txn (1.1x) | macOS fsync is buffered — durability tuning is a **CI-only** lever (Linux does real fsyncs, expect 2–10x there) |
| A test's worth of factory writes (6 rows, 1 txn) at the MySQL layer | ~0.5ms | The DB write path is NOT the bottleneck; the cost lives in PHP |
| Marginal cost of a trivial (`expect(true)`) test | ~52ms | Per-test framework tax: app rebuild + transaction wrap. The single biggest lever |
| Real feature test average (CreateQuoteTest, 80 tests) | ~125ms (≈52ms tax + ≈73ms body) | Body cost is Eloquent/factory/HTTP-kernel PHP work |
| Fixed cost per Pest process (post schema-dump squash) | ~14.4s | Paid per worker per run today; snapshots reduce to ~2s |
| CLI app boot | ~260ms warm | Confirms boot amortization is worth it |

**Wall-clock projections for 100k tests on 12 local cores:**

| Stack | Est. wall clock |
|---|---|
| Today's model, scaled | ~17–20 min |
| + warm workers (52ms tax → ~5ms) | ~11 min |
| + fixture universe + clonefile DB per worker | ~5–6 min |
| + content-addressed cache (typical diff invalidates 1–3%) | seconds – 1 min |

## Architecture

Five components. Each stage ships standalone value; later stages depend on earlier ones.

### 1. Warm-worker pool (executor)

N long-lived PHP processes each boot Laravel once, then execute tests in a loop.

- **State reset between tests** (Octane-style): container scoped-instances flushed, config restored from a pristine in-memory snapshot, facades and known statics cleared via a reset manifest, `Carbon::setTestNow(null)`, event/queue/mail fakes torn down.
- **Hermeticity sentinel:** after each test, diff env vars, config hash, and static-registry fingerprints against pristine. A leaking test fails loudly *with attribution* instead of poisoning neighbors. (Turns the env-mutation flake class — the one that took down 2/12 YardPilot CI shards — into a caught-at-source error.)
- **`#[Isolated]` escape hatch:** tests that legitimately can't share a process get a fresh one.
- **Correctness backstop:** CI periodically runs classic cold-process mode and diffs outcomes against warm-mode to detect reset gaps empirically.

### 2. Snapshot DB provisioning (state layer)

- **Golden datadir**, built once per schema change: migrate + seed (+ optional fixture universe), clean mysqld shutdown, stored keyed by `hash(migrations, seeders, MySQL version, engine version)`.
- **Per worker:** instant CoW clone of the golden datadir — APFS `clonefile` on macOS, OverlayFS or XFS/btrfs reflink on Linux — plus a throwaway `mysqld` on a unix socket. CI mysqld runs with durability off (`innodb_flush_log_at_trx_commit=0`, no doublewrite, no binlog).
- **Per test:** transaction-wrap as today. Tests needing committed state re-clone (sub-second).
- Eliminates per-worker `migrate:fresh`, worker-DB seeding races (each DB is born complete), and multi-minute fixed costs.
- **Fixture universe (optional convention, not required):** a rich immutable seeded "world" baked into the golden snapshot; tests read shared entities and create only their deltas inside transactions, cutting factory fan-out — the dominant body cost.

### 3. Content-addressed result cache (memoization layer)

- **Cache key:** `hash(test file content, transitive executed-code closure, schema hash, fixture hash, env fingerprint, engine version)`.
- **Closure source:** per-test pcov coverage recorded during full runs, stored as compact file-bitmap indexes. A change to one file invalidates exactly the tests whose recorded coverage includes it.
- **Remote shared cache** (S3/R2-style, CI-signed writes only initially; laptops read): a laptop "run" of 100k tests = verify ~97k hits + execute the invalidated ~3k.
- **Reconciler:** scheduled full runs bypass the cache, verify results, auto-invalidate divergences, and flag unstable-keyed tests. False negatives are caught in hours, not never — the answer to why naive test-impact analysis was rightly rejected (YardPilot REQ-481 option 7).
- **`#[Uncacheable]`** attribute + runtime detection heuristics (time/randomness/network use flags a test as always-run).

### 4. Orchestration, sharding, flakes

- `warp test` locally: git diff → invalidated set → schedule across warm workers; cache-verified results reported with provenance.
- CI: same engine; shards receive duration-balanced bins from recorded per-test timings (collapses count-based shard spread).
- **Flake protocol:** a failure auto-reruns once in full isolation (fresh process + fresh DB clone). Pass-on-retry = quarantined and tracked in a flake ledger — never silently green. Quarantine lane runs separately and blocks nothing until triaged.

### 5. Packaging & adoption

- A Pest plugin (`pest --warp` compatibility mode) + a runner daemon; Stages 1–3 are infra-only (unmodified tests). Stage 2's fixture universe and the cacheability conventions (S4/S5) are opt-in.
- The first adopter's suite (YardPilot) doubles as the conformance suite: engine mode vs classic mode outcome-diffing is the engine's own regression test.

## Staging

| Stage | Deliverable | Payoff at 100k tests |
|---|---|---|
| S1 | Warm-worker Pest runner + hermeticity sentinel | ~17min → ~11min local; flake class eliminated |
| S2 | Golden-snapshot DB provisioning (macOS + Linux) | Fixed cost 14.4s → ~2s; parallel-safe by construction |
| S3 | Per-test timing capture + duration-balanced CI sharding | CI shard spread collapses to mean |
| S4 | Coverage maps + local result cache | Typical diffs run in seconds locally |
| S5 | Remote shared cache + reconciler | 100k feels instant everywhere; scales past 100k |

**Gate between S1 and everything else:** a minimal warm-worker proof-of-concept on YardPilot must verify the 52ms→~5ms claim and demonstrate the state reset passes an outcome-diff against classic mode on at least one full domain suite.

## Risks

| Risk | Mitigation |
|---|---|
| State-reset correctness (the Octane hazard) | Hermeticity sentinel + cold-mode outcome-diff reconciler + `#[Isolated]` escape hatch |
| Coverage-map staleness → wrong invalidation | Conservative cache key (schema/env/engine included); reconciler auto-invalidates divergence |
| Cache poisoning / trust | CI-only signed writes to the remote cache initially |
| macOS multi-mysqld quirks (ports, sockets, ulimits) | Unix sockets, per-clone socket paths; PoC in S2 |
| Engine complexity becomes its own maintenance burden | Every stage ships standalone; classic mode always remains as fallback |

## Open questions (deferred to implementation planning)

- Language/runtime for the daemon (PHP for ecosystem fit vs Go/Rust for the orchestrator with PHP workers).
- Coverage bitmap format and storage (per-file vs per-class granularity trade-off).
- Whether S4's local cache precedes or follows S3 (S3 is CI-facing, S4 local-facing; order is swappable).
- Monetization/open-source posture — out of scope for this spec.
