---
description: Library release — CHANGELOG, quality gates, semver tag, Packagist via tag-only push.
argument-hint: '[patch|minor|major|vX.Y.Z] [--dry-run] [--yes] [--with-gh-release]'
---

# /release — Warp package release

Create a production release for **rawphp/warp** (Composer library).

**Ship path:** prepare `CHANGELOG.md` on **main** → push main → **local** gates (`scripts/release.sh`) → annotated `v*` tag → **tag-only** `git push` → Packagist picks up the GitHub tag. Optional GitHub Release via `--with-gh-release`. Branch pushes do **not** publish a new package version.

**Sole gate implementation:** `scripts/release.sh` is the only executable source of truth for quality gates, versioning, tag, and push. This command only orchestrates UX (confirm with the user, ensure CHANGELOG, pass an explicit bump, invoke the script, report). Do not re-run gates by hand and do not invent a second gate recipe.

## Arguments

Parse `$ARGUMENTS` (may be empty). Tokens map to `scripts/release.sh` flags:

| Token                       | Meaning                                                                              |
| --------------------------- | ------------------------------------------------------------------------------------ |
| `patch` / `minor` / `major` | **Force** that semver bump (only if the user passed it)                              |
| `vX.Y.Z`                    | Explicit tag — must be strictly greater than latest when a latest exists             |
| _(omit bump)_               | Script **infers** patch/minor/major from conventional commits since latest tag       |
| `--dry-run`                 | Preflight + gates only; do not tag or push                                           |
| `--skip-tests`              | Skip quality gates — **only with `--dry-run`** (script refuses otherwise)            |
| `--skip-suite`              | Skip full Pest only (hotfix). Prefer dual approval + documented reason               |
| `--with-gh-release`         | After tag push, create a GitHub Release (`gh`) from the CHANGELOG section            |
| `--yes`                     | Non-interactive after you have already confirmed with the user                       |

### Version policy

- **Default:** omit the bump. `scripts/release.sh` infers from commits since the latest tag:
  - **major** — `BREAKING CHANGE` or `type!:` / `type(scope)!:`
  - **minor** — `feat:` / `feature:`
  - **patch** — everything else (`fix:`, `chore:`, …)
- **Override only when the user passes** `patch`, `minor`, `major`, or `vX.Y.Z`.
- Do **not** invent a bump in the agent and pass it unless the user asked for that level.
- First release (no tags) → `v0.1.0`.

State the resolved version (auto vs explicit) in one sentence, then proceed.

## Hard rules

1. **Never** use `git push --force` or delete tags on origin.
2. **Never** push `main`/`master` as part of the tag step — only `git push origin refs/tags/<tag>` (the script does this). You **may** push main earlier if you committed CHANGELOG prep and HEAD was ahead of origin.
3. **Never** skip failing quality gates. Fix or abort.
4. **Never** create a tag on a dirty working tree.
5. **Never** release from a branch other than `main` or `master`.
6. **Never** ship without a `CHANGELOG.md` section for the new version (no `v` prefix in the heading).
7. Side effects (tag + push) need **explicit user confirmation** in this turn unless they already said e.g. "release patch now" / "ship it" / passed `--yes` after agreeing.

## Procedure

### 1. Preflight (read-only)

From the repo root:

```bash
git rev-parse --show-toplevel
git status -sb
git rev-parse --abbrev-ref HEAD
git fetch origin --tags
git tag -l 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -5
git log --oneline "$(git describe --tags --abbrev=0 2>/dev/null || echo HEAD~20)"..HEAD 2>/dev/null | head -40
```

If not on main/master: stop and report.

### 2. CHANGELOG (required before tag)

Target version **without** `v` must appear as a section heading in `CHANGELOG.md`:

```md
## 0.3.3 - 2026-07-29
```

If missing:

1. Review commits since last tag (and any `## Unreleased` notes).
2. Move user-facing notes from `## Unreleased` under the new version header at the top (keep an empty `## Unreleased` above it).
3. Commit on main: `chore: release vX.Y.Z`
4. **Push main** so `HEAD == origin/main`.

Do not invent highlights for pure internal-only changes; if there is nothing user-facing, still add a short honest entry (e.g. reliability / internals) rather than skipping the changelog.

### 3. Confirm the plan

Tell the user:

- latest tag (or "none — first release")
- proposed new tag
- that CHANGELOG has a section for that version
- that gates are whatever `scripts/release.sh` runs
- that only the tag will be pushed (Packagist updates from the tag)
- that origin/main must already include the commit being tagged

If they have not already approved shipping, ask once and wait.

### 4. Run the release script

Always invoke the in-repo script:

```bash
# Dry-run when the user is unsure (gates only):
./scripts/release.sh --dry-run [patch|minor|major|vX.Y.Z]
# optional dry-run-only: --skip-tests

# After confirmation:
./scripts/release.sh --yes [patch|minor|major|vX.Y.Z]
# optional: --with-gh-release
# never pass --skip-tests without --dry-run (script refuses)
```

Composer aliases (same script):

```bash
composer release:dry -- [args]
composer release -- --yes [args]
```

### 5. Report

On success, print:

```
Released vX.Y.Z
  commit: <short-sha>
  push:   tag only → origin
  package: rawphp/warp (Packagist)
  changelog: <version without v>
```

On gate failure: paste the failing script output + last relevant error lines, stop, do not tag.

### 6. Optional follow-ups (do not block the release)

- Offer Packagist page check / `composer show rawphp/warp` after a short delay.
- Offer `gh release create` if they did not pass `--with-gh-release`.

## Quality gates

**Do not duplicate gate commands here.** Whatever `scripts/release.sh` runs is authoritative. Inspect with:

```bash
./scripts/release.sh --help
# or dry-run:
composer release:dry
```

CI: `.github/workflows/ci.yml` (pint + pest on PHP 8.4/8.5 for PR + push to main — **no publish**).

## Out of scope

- Laravel Forge / app deploy — this package is Composer-only.
- App Store / mobile binaries.
- GitHub Releases UI notes — optional (`--with-gh-release`); do not block tag push unless the user required it.
