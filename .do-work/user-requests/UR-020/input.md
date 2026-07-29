---
ur: UR-020
received: 2026-07-28
status: captured
classification: bug-fix
layers_in_scope: []
layer_decisions: {}
reqs:
  - { id: REQ-114, layer: none, integration_confidence: n/a }
  - { id: REQ-115, layer: none, integration_confidence: n/a }
  - { id: REQ-116, layer: none, integration_confidence: n/a }
acknowledged_partials: []
open_gaps:
  - "Source of truth is yardpilot issue #271 body (Dirs::delete teardown races); URL-only brief under-specifies acceptance"
  - "Idempotent teardown needs a failure model (ENOENT ignore, not-empty retry, settle after mysqld stop)"
  - "Bare unlink/rmdir under Laravel error handler turns cleanup warnings into hard test fails"
  - "stop() does not guarantee quiet datadir; TOCTOU on InnoDB temp files remains without resilient delete"
  - "Aggressive swallow-all can hide real FS bugs — scope ignore to ENOENT + retry-on-not-empty only"
---

<!-- capture-summary-start -->
## Capture summary (2026-07-28)

| Item | Value |
|---|---|
| Classification | bug-fix |
| Layers in scope | (none — bug-fix) |
| Layer decisions | (none — all covered) |
| REQs generated | 3 |

| REQ | Layer | Integration confidence |
|---|---|---|
| REQ-114 | none | n/a |
| REQ-115 | none | n/a |
| REQ-116 | none | n/a |
<!-- capture-summary-end -->

# UR-020: User Request

## Request

https://github.com/original-solutions/yardpilot/issues/271
this issue is most likely in this warp repo
