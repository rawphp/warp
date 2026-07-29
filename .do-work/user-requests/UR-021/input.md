---
ur: UR-021
received: 2026-07-29
status: captured
classification: other-as-bug-fix
layers_in_scope: []
layer_decisions: {}
reqs:
  - { id: REQ-117, layer: none, integration_confidence: n/a }
  - { id: REQ-118, layer: none, integration_confidence: n/a }
acknowledged_partials: []
open_gaps:
  - "behavior-preserving must lock the UR-020 failure model (ENOENT / not-empty / non-ENOENT) so refactor does not silence real FS errors"
  - "dropping public $beforeFsOp needs a package-internal test seam so race unit tests stay meaningful"
  - "settle either/or is high-impact: fold into stop() vs delete and rely on Dirs delete+backoff"
  - "not-empty backoff multiplies recycle latency if sticky trees force full attempt budget"
  - "removing settleAfterStop without updating reflection duration tests leaves a red suite or false confidence"
---

<!-- capture-summary-start -->
## Capture summary (2026-07-29)

| Item | Value |
|---|---|
| Classification | other-as-bug-fix |
| Layers in scope | (none — bug-fix) |
| Layer decisions | (none — all covered) |
| REQs generated | 2 |

| REQ | Layer | Integration confidence |
|---|---|---|
| REQ-117 | none | n/a |
| REQ-118 | none | n/a |
<!-- capture-summary-end -->

# UR-021: User Request

## Request

Recommended restructure (behavior-preserving)

1. Dirs: one removeNode + retry loop; drop public $beforeFsOp or demote to internal test-only collaborator.
2. Retries: small backoff between not-empty attempts so delete is self-healing under residual churn.
3. Settle: either fold into MysqldServer::stop() or delete the method and rely on (1)+(2); do not keep a usleep-only private method as a third concept.
4. Keep the sweepDeadWorkers alive-after-TERM guard and its tests.
5. Re-run ./vendor/bin/pest (and the existing Dirs / teardown filters).

After that, this is an easy approve: same race fix, fewer moving pieces, no package-surface test seam.

## Clarifications

**Q:** Item 3 says settle: either fold into MysqldServer::stop() or delete the method and rely on resilient delete + backoff — no usleep-only private method. Which settle design do you want?
**A:** Delete settleAfterStop; rely on Dirs delete + backoff. Remove settleAfterStop and its duration test. Race resilience lives entirely in Dirs (removeNode + not-empty retry with small backoff). stop() stays process-lifecycle only.

**Q:** Item 1 says drop public $beforeFsOp or demote to an internal test-only collaborator. How should race injection work after the restructure?
**A:** Drop public hook; keep race tests via package-internal test seam. No public static on Dirs. Tests inject churn through an @internal / package-private collaborator (or similar non-public API) so mid-walk vanish and sticky not-empty stay unit-testable.

**Q:** The brief says “behavior-preserving.” What failure model must Dirs::delete still honor after the restructure?
**A:** Keep UR-020 model: ENOENT mid-walk = success; “directory not empty” retries a bounded number of times then [warp] RuntimeException; non-ENOENT failures still throw [warp]. No swallow-all.

**Q:** Race unit coverage today uses $beforeFsOp for mid-walk vanish and temporary not-empty. After demoting the seam, what must still be proven by automated tests?
**A:** Same race cases, new seam: (1) mid-walk unlink ENOENT does not ErrorException; (2) temporary not-empty retries then succeeds; (3) non-ENOENT still throws; (4) not-empty retries exhaust → [warp] throw — via the package-internal collaborator.

**Q:** Item 4 says keep the sweepDeadWorkers alive-after-TERM guard and its tests. Confirm scope while settle is removed?
**A:** Keep guard + tests unchanged. Do not regress the second alive() check after TERM+sleep. Teardown tests for live owner, live mysqld, and dead reap stay. Only remove settleAfterStop-related code/tests.

**Q:** Item 2 asks for a “small backoff” between not-empty retry attempts. How should that be specified?
**A:** Fixed short sleep ~10–20ms between attempts. Document a constant (e.g. NOT_EMPTY_BACKOFF_US) in Dirs; 5 attempts × small sleep stays cheap vs 100ms settle.
