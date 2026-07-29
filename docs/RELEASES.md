# Release process

How **rawphp/warp** versions, ships, and shows up on Packagist.

## Ship path (source of truth)

```
PR / push to main → ci.yml (pint + pest on PHP 8.4/8.5)
main tip (pushed) → CHANGELOG.md section → scripts/release.sh (local gates)
  → annotated v* tag → tag-only push → Packagist (GitHub tag) [optional GitHub Release]
```

| Rule | Detail |
| --- | --- |
| Publish trigger | **Only** annotated semver tags `vMAJOR.MINOR.PATCH` |
| Branch pushes | Do **not** publish a new package version |
| Remote CI | **`ci.yml`** — `composer validate`, pint, pest (no publish) |
| Executable ship gates | **`scripts/release.sh`** (local) — sole path that creates the tag |
| Full Pest | **Default ON** in `release.sh` |
| Hotfix suite skip | **`--skip-suite`** only — document reason |
| Packagist | Updates from the GitHub tag (webhook / auto-update) |
| Agent UX | **`/release`** (`.claude/commands/release.md`) + project skill `release` |
| User-facing notes | **`CHANGELOG.md` is a hard gate** |

```bash
# Preferred
./scripts/release.sh --dry-run
./scripts/release.sh --yes
# or: patch | minor | major | vX.Y.Z

composer release:dry --
composer release -- --yes

# Agent
/release
/release patch
/release --dry-run
/release --yes --with-gh-release
```

## Version numbering

Semantic Versioning with tags `vMAJOR.MINOR.PATCH`:

| Component | When to increment | Example |
| --- | --- | --- |
| **MAJOR** | Breaking changes | v0.3.2 → v1.0.0 |
| **MINOR** | Backward-compatible features | v0.3.2 → v0.4.0 |
| **PATCH** | Bug fixes, small improvements | v0.3.2 → v0.3.3 |

- **Default (no version arg):** infer `patch` / `minor` / `major` from conventional commits since the latest tag (`feat` → minor, `BREAKING CHANGE` / `type!:` → major, else patch).
- **Override only when passed:** `patch`, `minor`, `major`, or exact `vX.Y.Z`.

### Pre-1.0

Warp is currently `v0.x.x`. API and behaviour may change between minor versions until `v1.0.0`.

### Current version

```bash
git describe --tags --abbrev=0
```

There is no version field in `composer.json` — the package version is the git tag (Packagist).

## Creating a release

### When to release

- **Do:** user-facing fix, feature, or behaviour change consumers should pull
- **Don't:** pure internal chore with nothing worth a CHANGELOG line (if you still need a tag, write an honest note)

### Checklist

1. **On `main`**, clean tree, `HEAD == origin/main` after any prep push.
2. **Determine version** — omit bump for auto, or pass `patch` / `minor` / `major` / `vX.Y.Z`. Use `./scripts/release.sh --dry-run --skip-tests` to preview the resolved tag before editing CHANGELOG.
3. **Update `CHANGELOG.md`** (required) — move `## Unreleased` content under `## X.Y.Z - YYYY-MM-DD` (no `v` in the heading). Keep an empty `## Unreleased` at the top.
4. **Commit and push main:**
   ```bash
   git add CHANGELOG.md
   git commit -m "chore: release vX.Y.Z"
   git push origin main
   ```
5. **Run the release script** (gates → annotated tag → tag-only push):
   ```bash
   ./scripts/release.sh --yes
   # or: ./scripts/release.sh --yes minor
   # or: ./scripts/release.sh --yes --with-gh-release v0.4.0
   ```
6. **Verify** Packagist lists the new tag: https://packagist.org/packages/rawphp/warp

Do **not** hand-tag and push without the script unless you intentionally recreate every preflight and gate (prefer the script).

### What `scripts/release.sh` enforces

- Branch is `master` or `main`
- Working tree clean
- `HEAD` equals `origin/<branch>`
- Version from auto conventional-commit inference **or** explicit override
- Semver tag strictly greater than latest when bumping with explicit `vX.Y.Z`
- Non-empty commit range since previous tag (unless `--allow-empty-range`)
- **`CHANGELOG.md` section** for the target version (heading `## X.Y.Z` or `## X.Y.Z - date`)
- Default quality gates (unless `--skip-tests` **with** `--dry-run` only):
  1. `composer validate --strict`
  2. `./vendor/bin/pint --test`
  3. `composer audit --abandoned=ignore`
  4. `./vendor/bin/pest` (**full Pest — default ON**)
- Hotfix escape: `--skip-suite` skips **only** Pest (CHANGELOG + static gates still run)
- Annotated tag + **tag-only** push (never force; never push branch)
- Optional: `--with-gh-release` → `gh release create` with notes from the CHANGELOG section

### Hotfix: skipping full Pest (`--skip-suite`)

Full Pest is the default ship gate. Skipping is exceptional:

1. Named flag only: `./scripts/release.sh --yes --skip-suite [bump]`
2. Document reason (commit message or ops note)
3. Prefer shipping with suite green when CI is already green

`--skip-tests` remains **dry-run only** and is not a production hotfix path.

## GitHub Actions

| Workflow | Trigger | What runs |
| --- | --- | --- |
| **`ci.yml`** | `pull_request` + `push` to **`main`** | PHP 8.4 + 8.5: composer validate, install, pint `--test`, pest. **No publish.** |

Remote CI is active on every PR and push to main. **Tagging assumes a green CI** on the main tip you are shipping.

## Packagist

Package: **[rawphp/warp](https://packagist.org/packages/rawphp/warp)**  
Source: `https://github.com/rawphp/warp`

After the tag is on GitHub, Packagist should list the new version within a short auto-update window. If it does not, trigger an update from the Packagist package page (maintainer) or check the GitHub webhook.

## Agent entry points

| Surface | Path |
| --- | --- |
| Claude slash command | `.claude/commands/release.md` |
| Project skill | `.agents/skills/release/SKILL.md` (also mirrored under `.claude/skills/release/`) |
| Executable | `scripts/release.sh` |
