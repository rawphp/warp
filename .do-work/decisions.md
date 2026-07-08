# Decisions

2026-07-04 | UR-001 | REQs map 1:1 to the 9 tasks of docs/superpowers/plans/2026-07-04-warp-s1-warm-worker-poc.md; the plan document is the authoritative instruction source and REQ bodies defer to it | plan is reviewed and execution-ready; re-describing work risks drift
2026-07-04 | UR-001 | REQ-006 bases the yardpilot-warp worktree on `master`, deviating from the plan's `main` | YardPilot's actual default branch is master (verified via git worktree list)
2026-07-04 | UR-001 | UR-001 runs strictly serial (linear Depends-on chain 001→…→009, no parallelism) | YardPilot consumes warp via a symlinked path repo to the main checkout — integration REQs need prior REQs merged
2026-07-04 | UR-001 | yardpilot-warp worktree commits (branch warp-s1-poc) are external state outside warp's do-work merge queue; the main yardpilot checkout is never touched | a separate do-work worker runs parallel Pest in the main checkout
2026-07-04 | UR-001 | Integration REQs (006/008) may temporarily repoint yardpilot-warp's composer path repo at the worker's warp worktree during iteration, but must restore it to /Users/tomkaczocha/EA/projects/warp before final commit | worktree edits are invisible through the main-checkout symlink until merged
2026-07-05 | UR-003 | layer "integration" out of scope | user answered "No" at layer-coverage prompt; YardPilot-side WARP_WARM updates are a separate task in that repo
2026-07-05 | UR-003 | WARP_WARM renamed to WARP_MODE master switch accepting 1|on|true, clean break, no legacy alias | brand semantic "warp mode: engaged"; composable future layers, zero public users pre-release
2026-07-05 | UR-003 | package publishes as rawphp/warp under MIT | user owns the rawphp Packagist vendor (29 packages); rawphp/warp unregistered (checked 2026-07-05)
2026-07-05 | UR-003 | gate report keeps verbatim WARP_WARM benchmark output with a historical-rename note | dated historical record; rewriting recorded output would falsify evidence
2026-07-05 | UR-003 | REQ-014 (untrack .do-work) ordered last via hard deps on all sibling REQs | gitignoring .do-work mid-run would break do-work's own state commits
2026-07-05 | UR-003 | README rewrite is a single REQ under the install path-unit despite also serving the WARP_MODE path | single-file edit; two REQs touching one file would footprint-conflict
2026-07-07 | UR-006 | REQs map 1:1 to the 13 tasks of docs/superpowers/plans/2026-07-07-s2-snapshot-db-provisioning.md plus a 14th S2-gate REQ (REQ-033); the plan is the authoritative instruction source and REQ bodies defer to it | reviewed execution-ready plan; UR-001 precedent
2026-07-07 | UR-006 | S2 gate (REQ-033) runs against /Users/tomkaczocha/EA/projects/yardpilot-warp (branch warp-s1-poc); main yardpilot checkout never touched | REQ-006 standing constraint, confirmed at question gate
2026-07-07 | UR-006 | REQ-026 declares no dependency on backlog REQ-018 despite both editing phpunit.xml | edits touch different XML sections; footprint check serializes the file
2026-07-07 | UR-006 | integration-test REQs must show tests ran green, not skipped | mysqld (Herd) + pdo_mysql verified present on dev machine
2026-07-07 | UR-006/REQ-033 | yardpilot-warp's composer.json warp/warp -> rawphp/warp rename + composer.lock regen is done as part of REQ-033, testbed-only, main yardpilot checkout never touched | stale pre-rename requirement blocked REQ-033's bench scripts; user chose to fix in-REQ over a separate manual fix
2026-07-09 | UR-010 | REQs map 1:1 to the 10 tasks of docs/superpowers/plans/2026-07-08-s3-timing-capture-sharding.md; the plan document is the authoritative instruction source and REQ bodies defer to it | reviewed execution-ready plan; UR-001 and UR-006 precedent
