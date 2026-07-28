# REQ-113: Fix golden-build DB_* and timings supersede docs

**UR:** UR-019
**Status:** backlog
**Created:** 2026-07-28
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** docs/configuration.md, docs/usage.md, README.md
**Depends on:**

## Task

Correct two factual operator-doc errors (same claims in guides and README) on the `docs/user-facing-guide` branch / PR #1:

1. **Golden-build env injection** (`docs/configuration.md` “Required host wiring for sockets”, `README.md` host-wiring paragraph): document the real keys injected by `SnapshotDatabaseManager::runBuildCommand` — `DB_CONNECTION`, `DB_SOCKET`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` — not `DB_HOST`/`DB_PORT`. Add one short sentence that other parent `DB_*` values remain unless overridden in `warp.db.build_env`. Keep the required `'unix_socket' => env('DB_SOCKET', '')` wiring. Prefer “golden build mysqld” over “worker’s throwaway mysqld” for the build subprocess.

2. **Timings supersede / `--filter`** (`docs/usage.md` “Record on full runs”, `README.md` “Record timings on full runs”): replace the inverted claim that a `--filter` run “replaces a file’s entries with only the filtered subset.” State the completeness rule: method `--filter` runs upsert only (siblings retained); a file’s prior entries are superseded only when that file finished completely (every enumerated test terminated). Prefer full-suite recording so every file gets measured weight.

Do not change `docs/reports/*` design/gate prose. Do not address the out-of-scope `--suffix` discovery wording.

## Context

UR-019 intake of PR review bugs on operator docs. Clarifications: fix README + docs; state completeness rule for supersede; list real keys + short inheritance note (not a deep footgun essay). Source of truth: `src/Db/SnapshotDatabaseManager.php` (injected env), `src/Timing/TimingStore.php` + decision `2026-07-10 | UR-016 | … supersede per-file-when-complete`, integration test `does not supersede sibling timings from method-filtered captures`. Ideate flagged README as a duplicate surface; leaving it wrong would keep Packagist/GitHub lying after the guides are fixed.

## Acceptance Criteria

- [ ] `docs/configuration.md` golden-build injection list matches code: `DB_CONNECTION`, `DB_SOCKET`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (no claim that Warp injects `DB_HOST` or `DB_PORT` for the golden build)
- [ ] `docs/configuration.md` includes a short note that non-injected `DB_*` inherit from the parent process unless `warp.db.build_env` overrides them, and still requires `unix_socket` → `env('DB_SOCKET', '')`
- [ ] Golden-build wording prefers “golden build mysqld” over “worker’s throwaway mysqld” where that sentence describes the build subprocess
- [ ] `docs/usage.md` no longer claims a `--filter` run replaces a file’s timing entries with the filtered subset; wording states method-filter upserts only and supersede only when the file is complete
- [ ] `README.md` host-wiring and full-run timing paragraphs match the same corrected facts as the two guide pages
- [ ] No intentional changes to `docs/reports/**` or to the `--suffix` discovery bullets in `docs/usage.md`

## Verification Steps

1. **runtime** Confirm golden-build injected keys in code: `rg -n "DB_CONNECTION|DB_SOCKET|DB_DATABASE|DB_USERNAME|DB_PASSWORD|DB_HOST|DB_PORT" src/Db/SnapshotDatabaseManager.php` around `runBuildCommand`.
   - Expected: merge injects `DB_CONNECTION`, `DB_SOCKET`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` only; no `DB_HOST`/`DB_PORT` assignment in that merge array.

2. **runtime** Grep operator surfaces for the old wrong claims: `rg -n "DB_HOST|DB_PORT|filtered subset|replaces a file" docs/configuration.md docs/usage.md README.md`.
   - Expected: zero matches claiming golden-build injects `DB_HOST`/`DB_PORT`, and zero “filtered subset” / “replaces a file’s entries with only the filtered subset” supersede lies. (Mentions of `DB_HOST` only allowed if clearly describing parent inheritance / what is *not* injected.)

3. **runtime** Grep that corrected keys appear in the host-wiring sections: `rg -n "DB_CONNECTION" docs/configuration.md README.md`.
   - Expected: both files mention `DB_CONNECTION` in the golden-build injection list.

4. **runtime** Confirm supersede completeness wording is present: `rg -n "complete|upsert|filter" docs/usage.md README.md` (operator-facing paragraphs under record timings).
   - Expected: docs state that method `--filter` does not wipe sibling timings / only complete files supersede; prefer full-suite recording remains.

5. **test** `./vendor/bin/pest --filter="does not supersede sibling timings from method-filtered captures"`
   - Expected: pass (behaviour under documentation is unchanged; documents the rule operators are told).
