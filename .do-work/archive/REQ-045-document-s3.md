# REQ-045: Document S3 timing capture and CI sharding

**UR:** UR-010
**Status:** done
**Created:** 2026-07-09
**Layer:** docs
**Entry point:** README and CHANGELOG readers looking for Warp timing capture, sharding, CLI, and bench usage.
**Terminal state:** README and CHANGELOG accurately describe every new S3 env var, command, exit code, class, and bench command from REQs 036-044.
**Parent:**
**Closure proof:** Merged as `merge(REQ-045): document S3 timing sharding`; S3 terms grep passed, full Pest suite passed, and Pint passed.
**Criteria approved:** agent-drafted
**Priority:** 1
**Size:** M
**Files:** README.md, CHANGELOG.md
**Depends on:** REQ-036, REQ-037, REQ-038, REQ-039, REQ-040, REQ-041, REQ-042, REQ-043, REQ-044

## Task

Implement plan **Task 10**: update `README.md` with the S3 timing capture and duration-balanced CI sharding section, extend the public API and benchmark documentation, and add the S3 entry under `CHANGELOG.md` Unreleased.

## Context

This docs REQ closes the user-facing S3 path after the API, CLI, extension, and bench harness are implemented. The plan's prose is the authoritative source; ensure the final docs match the exact class names, env vars, commands, and exit codes that were implemented.

## Acceptance Criteria

- [x] README documents registering `RawPHP\Warp\Timing\TimingExtension` in `phpunit.xml`.
- [x] README documents `WARP_TIMINGS`, `WARP_TIMINGS_DIR`, `.warp/timings`, full-run timing capture, `warp timings`, and `warp shard <k>/<n>`.
- [x] README explains stdout/stderr behavior, deterministic shard agreement, no-timings fallback, and exit code 3 for empty shards.
- [x] README public API table includes `WarpMode::timingsEnabled()`, `TimingExtension`, `TimingStore`, `DurationBalancedSharder`, and `bin/warp`.
- [x] README benchmark commands include `bench/shard-spread.sh /path/to/your/app 8`.
- [x] CHANGELOG Unreleased includes the S3 timing capture, sharding CLI, env override, Composer binary, and bench harness.

## Verification Steps

1. **runtime** `grep -nE 'WARP_TIMINGS|warp shard|warp timings|TimingExtension|DurationBalancedSharder|shard-spread' README.md CHANGELOG.md`
   - Expected: matches show every S3 public term documented.
   - Actual: PASS.
2. **test** `./vendor/bin/pest`
   - Expected: full suite PASS.
   - Actual: PASS, 188 tests / 437 assertions.
3. **format** `./vendor/bin/pint --dirty`
   - Actual: PASS.

## Integration

**Reachability:** Package users read `README.md`; release readers read `CHANGELOG.md`.

**Data dependencies:** Documents the public behavior produced by REQs 036-044.

**Service dependencies:** Extends existing README and changelog structure in `README.md` and `CHANGELOG.md`.
