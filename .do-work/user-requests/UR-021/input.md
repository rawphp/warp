---
ur: UR-021
received: 2026-07-29
status: intake
---

# UR-021: User Request

## Request

Recommended restructure (behavior-preserving)

1. Dirs: one removeNode + retry loop; drop public $beforeFsOp or demote to internal test-only collaborator.
2. Retries: small backoff between not-empty attempts so delete is self-healing under residual churn.
3. Settle: either fold into MysqldServer::stop() or delete the method and rely on (1)+(2); do not keep a usleep-only private method as a third concept.
4. Keep the sweepDeadWorkers alive-after-TERM guard and its tests.
5. Re-run ./vendor/bin/pest (and the existing Dirs / teardown filters).

After that, this is an easy approve: same race fix, fewer moving pieces, no package-surface test seam.
