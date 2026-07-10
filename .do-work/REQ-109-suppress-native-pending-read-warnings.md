# REQ-109: Suppress native PHP warnings on pending-batch reads

**UR:** UR-017
**Status:** backlog
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php
**Depends on:**

## Task

Fix finding 11: `file_get_contents()` on pending batches (and the pending-dir `scandir()`) run without `@`-suppression or a scoped error handler. When a batch vanishes between the is_file check and the read — a path the code itself anticipates with the "skipped vanished pending timings batch" warning — PHP's native E_WARNING goes to the process's REAL stderr/display, bypassing the injected warn sink that REQ-101 threaded through TimingStore precisely so embedded callers (WarpCli::run with captured streams) get clean output.

Fix: suppress the native diagnostics on those filesystem calls (`@` plus explicit false-handling, or a scoped error handler like FileLock's) so the ONLY output for a vanished/unreadable batch is the store's own injected warning. Do not change the skip/warn semantics established by REQ-095.

## Context

Review finding 11, CONFIRMED (no suppression at the read site; the only error handler in src is scoped inside FileLock). This is the last leak around the REQ-101 stderr-threading work. Pure hardening — behavior semantics unchanged.

## Acceptance Criteria

- [ ] A pending batch vanishing between scandir and read produces the injected "skipped vanished" warning and NOTHING on the process's native stderr (assert via subprocess with captured real stderr)
- [ ] An unreadable pending dir/batch likewise emits only injected warnings
- [ ] Skip/warn/retry semantics from REQ-095 are byte-identical (existing tests unchanged)
- [ ] All existing TimingStore tests green

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce finding 11 first: subprocess triggering a vanished-batch read with real stderr captured → assert native warning text absent (must fail pre-fix with "Failed to open stream" leaking)
   - Expected: red-then-green; only the injected sink line present
2. **test** REQ-095 regression filter: `./vendor/bin/pest --filter=TimingStoreTest`
   - Expected: all green
