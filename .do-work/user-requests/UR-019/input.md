---
ur: UR-019
received: 2026-07-28
status: intake
---

# UR-019: User Request

## Request

run intake on the 2 bugs

Bug 1 — docs/configuration.md:86 (golden-build DB_* injection list wrong)

The golden-build env list is wrong. Docs claim Warp injects `DB_HOST`, `DB_PORT`, `DB_SOCKET`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`. `SnapshotDatabaseManager::runBuildCommand()` actually injects `DB_CONNECTION`, `DB_SOCKET`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` only (merged over inherited `getenv()`, then overridden by `build_env`). It does **not** set `DB_HOST` or `DB_PORT`; parent values remain unless the host clears them via `build_env`. Operators debugging “migrated against the wrong server” or customizing `build_env` will follow incorrect wiring.

Fix: Document the real injected keys: `DB_CONNECTION`, `DB_SOCKET`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Note that other `DB_*` values are inherited from the parent process unless overridden in `warp.db.build_env`, and that the critical host requirement remains `'unix_socket' => env('DB_SOCKET', '')` so the build hits the temporary golden-build socket (not inventing a `DB_HOST`/`DB_PORT` injection that does not exist). Prefer “golden build mysqld” over “worker’s throwaway mysqld” for the build subprocess.

Bug 2 — docs/usage.md:102 (--filter timings supersede claim inverted)

Claim is inverted relative to the implementation. Docs say a `--filter` run “replaces a file’s entries with only the filtered subset.” Timing store supersede is per-file and only when a batch flags the file complete (every *enumerated* test terminated). Method-filtered Pest/PHPUnit runs leave siblings incomplete and **upsert only**; integration test `does not supersede sibling timings from method-filtered captures` asserts exactly that. Whole-file path runs *do* supersede that file’s prior entries. Preferring full-suite recording is still good advice (coverage of all files), but the stated reason is factually wrong and can mislead CI timing hygiene.

Fix: Replace the supersede claim with the real rules: prefer full-suite recording so every file gets measured weight; method `--filter` runs do not wipe sibling test ids; re-running complete files (or whole-file paths) supersedes that file’s prior entries only.

Context: branch docs/user-facing-guide / PR https://github.com/rawphp/warp/pull/1 — operator docs review findings (2 bugs only; the --suffix suggestion is out of scope for this intake unless capture includes it).

## Clarifications

**Q:** Confirm inferences: --suffix out of scope; docs/reports not in scope; fix on docs/user-facing-guide / PR #1; Bug1 truth = SnapshotDatabaseManager injects DB_CONNECTION/SOCKET/DATABASE/USERNAME/PASSWORD only; Bug2 truth = per-file supersede when complete (UR-016 / TimingStore), method --filter upserts only.
**A:** Confirm all. *(inferred, confirmed)*

**Q:** The brief only names docs/configuration.md and docs/usage.md, but README.md repeats both wrong claims. Should this UR fix README too?
**A:** Yes — fix README + docs. Same two factual corrections in README.md so Packagist/GitHub surface matches the guides.

**Q:** Bug 2’s suggested fix says “whole-file path runs supersede.” Code only supersedes when the file is complete. How strict should operator wording be?
**A:** State completeness rule. Say method --filter upserts only; a file’s prior entries are replaced only when that file finished completely (all enumerated tests terminated). Prefer full-suite recording for full weight coverage.

**Q:** Bug 1: how deep should the operator warning go for injected keys vs inherited DB_*?
**A:** List real keys + short inheritance note. Correct the injected list; one sentence that parent DB_* remain unless build_env overrides; keep unix_socket requirement; rename “throwaway mysqld” → “golden build mysqld.”
