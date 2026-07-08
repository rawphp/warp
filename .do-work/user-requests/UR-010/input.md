---
ur: UR-010
received: 2026-07-09
status: captured
classification: feature
layers_in_scope: [package, bench, integration, docs]
layer_decisions: {}
open_gaps:
  - UR-010/input.md stores only a plan path, so plan edits could drift from the captured REQs.
  - Timing artifacts can lose useful data if filtered runs supersede full-file timings.
  - CI shard agreement depends on stable path forms, fallback weights, and tie breaks.
  - PHPUnit extension classes must stay safe despite no runtime PHPUnit dependency in composer.json.
  - warp shard must keep stdout file-only across warnings, usage errors, and empty shards.
reqs:
  - { id: REQ-036, layer: package, integration_confidence: high }
  - { id: REQ-037, layer: package, integration_confidence: high }
  - { id: REQ-038, layer: package, integration_confidence: high }
  - { id: REQ-039, layer: package, integration_confidence: high }
  - { id: REQ-040, layer: integration, integration_confidence: high }
  - { id: REQ-041, layer: package, integration_confidence: high }
  - { id: REQ-042, layer: package, integration_confidence: high }
  - { id: REQ-043, layer: package, integration_confidence: high }
  - { id: REQ-044, layer: bench, integration_confidence: high }
  - { id: REQ-045, layer: docs, integration_confidence: high }
acknowledged_partials: []
---

<!-- capture-summary-start -->
## Capture summary (2026-07-09)

| Item | Value |
|---|---|
| Classification | feature |
| Layers in scope | package, bench, integration, docs |
| Layer decisions | (none — all covered) |
| REQs generated | 10 |

| REQ | Layer | Integration confidence |
|---|---|---|
| REQ-036 | package | high |
| REQ-037 | package | high |
| REQ-038 | package | high |
| REQ-039 | package | high |
| REQ-040 | integration | high |
| REQ-041 | package | high |
| REQ-042 | package | high |
| REQ-043 | package | high |
| REQ-044 | bench | high |
| REQ-045 | docs | high |
<!-- capture-summary-end -->

# UR-010: User Request

## Request

/Users/tomkaczocha/EA/projects/warp/docs/superpowers/plans/2026-07-08-s3-timing-capture-sharding.md
