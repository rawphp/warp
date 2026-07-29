---
name: release
description: >
  Ship a rawphp/warp Composer package release (CHANGELOG, quality gates, annotated
  semver tag, tag-only push for Packagist). Use when the user says /release,
  "release warp", "ship warp", "tag a release", "publish to Packagist", or wants
  a dry-run of the release script. Differentiator: library ship path only —
  CHANGELOG hard gate, no product-updates.json, no Forge deploy.
---

# Warp release

Create a production release for **rawphp/warp**.

**Ship path:** prepare `CHANGELOG.md` on **main** → push main → local gates via `scripts/release.sh` → annotated `v*` tag → **tag-only** push → Packagist updates from the GitHub tag. Optional GitHub Release with `--with-gh-release`.

**Sole gate implementation:** `scripts/release.sh` is the only executable source of truth. Orchestrate UX only — confirm with the user, ensure CHANGELOG, pass an explicit bump only if they asked, invoke the script, report. Do not invent a second gate recipe.

For the full agent procedure and hard rules, also read `.claude/commands/release.md` if present (same workflow). Human docs: `docs/RELEASES.md`.

## Arguments

Map user tokens to `scripts/release.sh`:

| Token | Meaning |
| --- | --- |
| `patch` / `minor` / `major` | Force that semver bump (only if the user passed it) |
| `vX.Y.Z` | Explicit tag (must be strictly greater than latest) |
| _(omit bump)_ | Infer from conventional commits since latest tag |
| `--dry-run` | Preflight + gates only |
| `--skip-tests` | Skip gates — **only with `--dry-run`** |
| `--skip-suite` | Skip Pest only (hotfix escape) |
| `--with-gh-release` | After tag push, `gh release create` from CHANGELOG |
| `--yes` | Non-interactive after user already confirmed |

### Version policy

- Default: omit bump; script infers major (`BREAKING CHANGE` / `type!:`) / minor (`feat:`) / patch (else).
- Override only when the user passes `patch`, `minor`, `major`, or `vX.Y.Z`.
- Do not invent a bump unless they asked for that level.
- First release (no tags) → `v0.1.0`.

State auto vs explicit version in one sentence, then proceed.

## Hard rules

1. Never `git push --force` or delete tags on origin.
2. Never push main as part of the tag step — only `refs/tags/<tag>` (script does this). May push main earlier for CHANGELOG prep.
3. Never skip failing quality gates. Fix or abort.
4. Never tag a dirty working tree.
5. Never release from a branch other than `main` or `master`.
6. Never ship without a `CHANGELOG.md` section for the version (no `v` in the heading).
7. Tag + push need explicit user confirmation this turn unless they already said "ship it" / "release now" / passed `--yes` after agreeing.

## Procedure

### 1. Preflight (read-only)

```bash
git rev-parse --show-toplevel
git status -sb
git rev-parse --abbrev-ref HEAD
git fetch origin --tags
git tag -l 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -5
git log --oneline "$(git describe --tags --abbrev=0 2>/dev/null || echo HEAD~20)"..HEAD 2>/dev/null | head -40
```

Stop if not on main/master.

### 2. CHANGELOG (required)

Heading form:

```md
## 0.3.3 - 2026-07-29
```

If missing: move `## Unreleased` notes under the new header, keep empty `## Unreleased`, commit `chore: release vX.Y.Z`, **push main** so `HEAD == origin/main`.

### 3. Confirm plan

Report latest tag, proposed tag, CHANGELOG section present, gates = script, tag-only push → Packagist, origin/main must already include the commit. Ask once if not already approved.

### 4. Run script

```bash
./scripts/release.sh --dry-run [patch|minor|major|vX.Y.Z]
./scripts/release.sh --yes [patch|minor|major|vX.Y.Z]
# optional: --with-gh-release
# never --skip-tests without --dry-run
```

Aliases:

```bash
composer release:dry -- [args]
composer release -- --yes [args]
```

### 5. Report

Success:

```
Released vX.Y.Z
  commit: <short-sha>
  push:   tag only → origin
  package: rawphp/warp (Packagist)
  changelog: <version without v>
```

Gate failure: paste failing output, stop, do not tag.

### 6. Optional follow-ups

Packagist page, `composer show rawphp/warp`, or `gh release create` if not used.

## Quality gates

Do not duplicate commands. Inspect:

```bash
./scripts/release.sh --help
composer release:dry
```

CI: `.github/workflows/ci.yml` (pint + pest; no publish).

## Out of scope

- Forge / app deploy
- Mobile binaries
- Blocking on GitHub Releases unless user required `--with-gh-release`
