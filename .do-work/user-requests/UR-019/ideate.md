# Ideate — UR-019

**Reviewed:** 2026-07-28

## Explorer — Assumptions & Perspectives

- **README mirrors the same two wrong claims, but the brief only names `docs/` files.** `README.md:176–177` still lists golden-build injection of `DB_HOST`/`DB_PORT` (not `DB_CONNECTION`), and `README.md:242–243` still says a `--filter` run replaces a file’s timing entries with the filtered subset. An operator who only reads the package README (Packagist / GitHub landing) never hits `docs/configuration.md` or `docs/usage.md`, so fixing only the guide leaves the public surface wrong. Trigger: Bug 1/2 fix targets plus Context limiting scope to the PR’s operator docs.
- **“Prefer full suite recording” is still good advice, but for a different reason than the brief’s rewrite must state.** After the supersede fix, full runs matter because incomplete/filtered processes never mark files complete (per `TimingCollector` + UR-016 decision: supersede only when every enumerated test terminated). A CI job that records only `Feature` via path still supersedes that path’s files; a method `--filter` does not. Operators writing “quick refresh” jobs care about this distinction more than the wrong “replaces with subset” story. Trigger: Bug 2 fix wording.
- **Inherited parent `DB_*` is the real footgun once docs stop inventing `DB_HOST` injection.** `runBuildCommand` merges `getenv()` first, then injects five keys, then `build_env`. A host with `DB_HOST=127.0.0.1` and a connection that prefers host over empty `unix_socket` can still migrate against the wrong server even after the doc list is corrected—unless the guide stresses socket wiring and optional `build_env` clears. Trigger: Bug 1 note about inheritance / `build_env`.
- **Audience split is undefined:** package authors vs app operators embedding Warp. Bug 1’s `config/database.php` example is host-app work; Bug 2 is CI/timings hygiene. Decomposition should keep operator-facing copy in both guides and README consistent without reopening design docs under `docs/reports/` or `docs/specs/`. Trigger: Context PR #1 operator-docs scope.

## Challenger — Risks & Edge Cases

- **Partial fix creates doc drift between README and `docs/`.** If capture scopes REQs only to `docs/configuration.md` and `docs/usage.md`, the next reader who greps “DB_HOST” or “filtered subset” still finds the wrong story in README and may “fix” docs back to match README later. Scenario: PR merges guide fixes only; Packagist README stays wrong. Trigger: brief file list vs codebase duplicate claims.
- **Overselling “whole-file path runs supersede” without the completeness rule can reintroduce a milder lie.** Path args that still leave some tests unrun (or a process crash mid-file) do not complete the file; only full termination of the enumerated suite for that file supersedes. Scenario: docs say “path run supersedes” → operator runs one method via path+filter and expects siblings wiped. Trigger: Bug 2 suggested fix wording about “whole-file path runs.”
- **No automated regression for prose.** Acceptance will be “matches `SnapshotDatabaseManager::runBuildCommand` / `TimingStore` apply rules.” Without a checklist or cite-the-source verification step, a well-meaning edit can invent keys again (e.g. adding `DB_HOST=` empty as “best practice” that is not what code does). Trigger: both bugs are doc-only factual fixes.
- **Branch/delivery risk:** work lives on `docs/user-facing-guide` (PR #1). Fixing on `main` or a side branch without stacking onto the PR leaves the open PR still wrong for reviewers. Trigger: Context branch/PR note.

## Connector — Links & Reuse

- **Source of truth already in code + decisions, not in older gate prose.** Injected keys: `src/Db/SnapshotDatabaseManager.php` (`DB_CONNECTION`, `DB_SOCKET`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Supersede: `TimingStore` per-file complete map + decision `2026-07-10 | UR-016 | completeness is per-file… supersede per-file-when-complete`. Integration test `does not supersede sibling timings from method-filtered captures` is the behavioural proof to cite in verification, not to change.
- **Prior doc REQs (REQ-032, REQ-045, REQ-062, REQ-013) already established README + warp.db + S3 timing docs**—this UR is a surgical correction of copy that drifted from later S2/S3 implementation (UR-016 completeness redesign), not a new documentation system.
- **`docs/reports/2026-07-07-s2-gate.md` still narrates `DB_HOST=localhost` in a design/measurement voice.** That is not the operator guide; leave reports alone unless capture explicitly expands scope. Connector: avoid mixing “fix historical design notes” into this bugfix UR.
- **Out-of-scope by brief:** `--suffix` discovery wording (`docs/usage.md` ~130) remains a third review finding; do not fold it in unless capture/user expands scope.

## Summary

This is a small, high-confidence doc accuracy fix on the open user-facing-guide PR, but the same two lies already live in `README.md`—omitting them leaves the primary public surface wrong. Align supersede wording with the UR-016 per-file-completeness decision (not “path run always replaces”), and keep verification pinned to `runBuildCommand` + TimingStore/tests so the next rewrite does not reintroduce invented env keys.
