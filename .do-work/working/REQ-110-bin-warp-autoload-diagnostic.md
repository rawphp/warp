# REQ-110: Friendly diagnostic when bin/warp finds no autoloader

<!-- claimed-start -->
**Claimed by:** Toms-MacBook-Pro.local.dw17
**Claimed at:** 2026-07-10T05:58:12Z
**Heartbeat:** 2026-07-10T05:58:12Z
<!-- claimed-end -->

**UR:** UR-017
**Status:** in-progress
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** bin/warp, tests/Integration/Cli/WarpBinTest.php
**Depends on:**

## Task

Fix finding 12: `bin/warp`'s autoload loop (`__DIR__/../../../autoload.php`, `__DIR__/../vendor/autoload.php`) has no else branch — when neither exists (repo clone or dist archive before `composer install`), execution falls through to `WarpCli::run(...)` and PHP fatals with an uncaught "Class not found" at exit 255.

Fix: when no autoload candidate exists, print a one-line `[warp]` diagnostic to STDERR telling the user to run `composer install`, and exit with a nonzero code (match the existing CLI error exit convention, exit 2). No autoloader means no classes — the message must be self-contained plain PHP in bin/warp.

## Context

Review finding 12, CONFIRMED. Small UX hardening on the CLI entry point; consistent with the UR-016 REQ-096 decision that CLI failures surface as `[warp]` diagnostics, never raw fatals.

## Acceptance Criteria

- [ ] Running `php bin/warp shard 1/2` with both autoload candidates absent prints a single `[warp]` line to stderr mentioning `composer install` and exits 2 — no PHP fatal, no stack trace
- [ ] Normal operation with vendor/ present is byte-identical (existing WarpBinTest passes unchanged)

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** Reproduce finding 12 first: integration test copying bin/warp into a temp tree with no vendor/ → assert exit 2 + `[warp]` stderr line, no "Fatal error" text (must fail pre-fix with exit 255 fatal)
   - Expected: red-then-green
2. **test** `./vendor/bin/pest --filter=WarpBinTest`
   - Expected: all green
