#!/usr/bin/env bash
# Warp Composer package release: quality gates → annotated tag → push tag only.
#
# rawphp/warp is a library. Pushing an annotated semver tag updates Packagist
# (GitHub → Packagist webhook / auto-update). There is no Forge deploy and no
# product-updates.json. CHANGELOG.md is the user-facing hard gate.
#
# Usage:
#   scripts/release.sh [--dry-run] [--yes] [--skip-tests] [--skip-suite]
#                      [--allow-empty-range] [--with-gh-release]
#                      [patch|minor|major|vX.Y.Z]
#
# Version: if patch|minor|major|vX.Y.Z is passed, use it. If omitted, infer the
# bump from conventional-commit subjects/bodies since the latest tag (see
# infer_bump_from_commits). First release with no tags → v0.1.0.
#
# Flow:
#   preflight (main/master + clean + fetch + HEAD==origin/$BRANCH) →
#   version resolve (explicit or auto) → CHANGELOG gate → plan →
#   (confirm if interactive) → quality gates → porcelain re-check → tag → push
# Dry-run stops after gates (no tag/push). Confirm is never after gates.
# Empty range (prior tag + 0 commits since) hard-fails unless --allow-empty-range.
# If tag push fails after create, the local tag is deleted so re-run is clean.
#
# Default quality gates:
#   1. CHANGELOG.md section for the target version (always, even dry-run)
#   2. composer validate --strict
#   3. pint --test
#   4. composer audit --abandoned=ignore
#   5. pest (full suite — default ON)
#
# Remote CI (.github/workflows/ci.yml) runs pint + pest on PHP 8.4/8.5 for every
# PR and push to main. Prefer green CI before tagging.
#
# Hotfix escape: --skip-suite opts out of local Pest only. Document the reason.
# --skip-tests remains dry-run only.
# --with-gh-release also runs `gh release create` after a successful tag push.

set -euo pipefail

DRY_RUN=0
ASSUME_YES=0
SKIP_TESTS=0
# Full Pest is ON by default. Use --skip-suite only for dual-approved hotfixes.
WITH_SUITE=1
ALLOW_EMPTY_RANGE=0
WITH_GH_RELEASE=0
# Empty = infer from conventional commits since latest tag. Set only if user passes
# patch|minor|major|vX.Y.Z.
BUMP=""
BUMP_SOURCE=""

CHANGELOG_PATH="CHANGELOG.md"

usage() {
  cat <<'EOF'
Usage: scripts/release.sh [options] [patch|minor|major|vX.Y.Z]

Quality-gate a Warp library release, create an annotated semver tag, and push
ONLY that tag to origin (Packagist picks up GitHub tags; no branch push).

Options:
  --dry-run            Run preflight + gates; print the tag that would be created
                       (no tag, no push, no confirm prompt)
  --yes                Skip the interactive confirmation prompt (agent / CI path)
  --skip-tests         Skip quality gates — ONLY allowed with --dry-run
  --skip-suite         Skip full Pest only (hotfix escape). Does NOT skip
                       CHANGELOG / composer validate / pint / composer audit.
  --allow-empty-range  Allow release when a prior tag exists and HEAD has zero
                       commits since that tag (default: hard refuse empty range)
  --with-gh-release    After tag push, create a GitHub Release via `gh`
                       (notes from the matching CHANGELOG section)
  -h, --help           Show this help

Default gates (always, unless --skip-tests dry-run):
  CHANGELOG section, composer validate --strict, pint --test,
  composer audit --abandoned=ignore, pest (full suite — default ON)

Version argument (optional — only applied when passed):
  (omit)           Infer bump from commits since latest tag (conventional commits)
  patch            v0.1.0 → v0.1.1  (first release: v0.1.0)
  minor            v0.1.0 → v0.2.0  (first release: v0.1.0)
  major            v0.1.0 → v1.0.0  (first release: v1.0.0)
  vX.Y.Z           explicit tag — must be strictly greater than latest when
                   a latest tag exists (hard refuse; no override)

Auto-bump rules (when version arg omitted), from commits since latest tag:
  major  — BREAKING CHANGE in body, or type with ! (feat!:, fix!:, …)
  minor  — feat: / feature: (no breaking)
  patch  — everything else (fix:, chore:, docs:, refactor:, …)
  first release (no tags) → v0.1.0

Hard gate (always):
  CHANGELOG.md must contain a section heading for the target version without
  the leading "v", e.g. tag v0.3.3 requires one of:
    ## 0.3.3
    ## 0.3.3 - YYYY-MM-DD
  Prefer moving ## Unreleased → that header before tagging.

Examples:
  scripts/release.sh --dry-run
  scripts/release.sh --yes                 # default gates + full Pest + tag
  scripts/release.sh --yes --skip-suite    # hotfix: document reason
  scripts/release.sh minor
  scripts/release.sh --yes --with-gh-release v0.4.0
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1; shift ;;
    --yes|-y) ASSUME_YES=1; shift ;;
    --skip-tests) SKIP_TESTS=1; shift ;;
    --skip-suite) WITH_SUITE=0; shift ;;
    --allow-empty-range) ALLOW_EMPTY_RANGE=1; shift ;;
    --with-gh-release) WITH_GH_RELEASE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    patch|minor|major)
      BUMP="$1"
      BUMP_SOURCE="explicit"
      shift
      ;;
    v[0-9]*.[0-9]*.[0-9]*)
      if [[ ! "$1" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "error: invalid version argument: $1 (expected patch|minor|major|vX.Y.Z)" >&2
        usage >&2
        exit 2
      fi
      BUMP="$1"
      BUMP_SOURCE="explicit"
      shift
      ;;
    *)
      echo "error: unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ "$SKIP_TESTS" -eq 1 && "$DRY_RUN" -eq 0 ]]; then
  echo "error: --skip-tests is only allowed with --dry-run (real releases must run quality gates)" >&2
  exit 1
fi

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [[ -z "$ROOT" ]]; then
  echo "error: not inside a git repository" >&2
  exit 1
fi
cd "$ROOT"

log() { printf '==> %s\n' "$*"; }
fail() { printf 'error: %s\n' "$*" >&2; exit 1; }

# --- preflight ----------------------------------------------------------------

log "Preflight"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$BRANCH" != "main" && "$BRANCH" != "master" ]]; then
  fail "release from main/master only (current branch: $BRANCH)"
fi

if [[ -n "$(git status --porcelain)" ]]; then
  git status --short
  fail "working tree is dirty — commit or stash before releasing (include CHANGELOG.md)"
fi

log "Fetching tags and branch tip from origin"
if ! git fetch origin --tags --quiet; then
  fail "git fetch origin --tags failed — cannot resolve latest version safely"
fi
git fetch origin "$BRANCH" --quiet 2>/dev/null || true

HEAD_SHA="$(git rev-parse HEAD)"
HEAD_SHORT="$(git rev-parse --short HEAD)"
log "HEAD $HEAD_SHORT on $BRANCH"

# Packagist installs from the tag SHA, but the public main tip should still
# equal the tagged commit so consumers and docs stay coherent.
if git rev-parse --verify "origin/$BRANCH" >/dev/null 2>&1; then
  ORIGIN_SHA="$(git rev-parse "origin/$BRANCH")"
  if [[ "$ORIGIN_SHA" != "$HEAD_SHA" ]]; then
    if git merge-base --is-ancestor "$HEAD_SHA" "origin/$BRANCH"; then
      fail "HEAD ($HEAD_SHORT) is behind origin/$BRANCH — checkout latest or pick the tip to tag"
    fi
    if git merge-base --is-ancestor "origin/$BRANCH" "$HEAD_SHA"; then
      git log --oneline "origin/$BRANCH..HEAD" | head -10
      fail "HEAD is ahead of origin/$BRANCH — push the branch first (tag the published tip)"
    fi
    fail "HEAD is not on origin/$BRANCH — cannot safely release"
  fi
  log "origin/$BRANCH is at $HEAD_SHORT (branch tip matches tag)"
else
  if [[ "$DRY_RUN" -eq 0 ]]; then
    fail "origin/$BRANCH not found after fetch — push the branch before a real release (or use --dry-run)"
  fi
  log "warning: origin/$BRANCH not found locally after fetch — ensure the branch is pushed before tagging"
fi

# --- version ------------------------------------------------------------------

latest_tag() {
  local local_tag remote_tag
  local_tag="$(git tag -l 'v[0-9]*.[0-9]*.[0-9]*' \
    | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' \
    | sort -V \
    | tail -1 || true)"
  remote_tag="$(git ls-remote --tags --refs origin 'v*' 2>/dev/null \
    | awk '{print $2}' \
    | sed 's#refs/tags/##' \
    | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' \
    | sort -V \
    | tail -1 || true)"

  if [[ -z "$local_tag" && -z "$remote_tag" ]]; then
    printf ''
    return
  fi
  if [[ -z "$local_tag" ]]; then
    printf '%s' "$remote_tag"
    return
  fi
  if [[ -z "$remote_tag" ]]; then
    printf '%s' "$local_tag"
    return
  fi
  printf '%s\n%s\n' "$local_tag" "$remote_tag" | sort -V | tail -1
}

next_version() {
  local current="$1" kind="$2"
  if [[ "$kind" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    printf '%s' "$kind"
    return
  fi

  if [[ -z "$current" ]]; then
    case "$kind" in
      major) printf 'v1.0.0' ;;
      minor) printf 'v0.1.0' ;;
      patch) printf 'v0.1.0' ;;
      *) fail "unknown bump: $kind" ;;
    esac
    return
  fi

  if [[ ! "$current" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    fail "latest tag is not strict vX.Y.Z: $current (cannot bump safely)"
  fi

  local major minor patch
  IFS=. read -r major minor patch <<<"${current#v}"
  case "$kind" in
    major) major=$((major + 1)); minor=0; patch=0 ;;
    minor) minor=$((minor + 1)); patch=0 ;;
    patch) patch=$((patch + 1)) ;;
    *) fail "unknown bump: $kind" ;;
  esac
  printf 'v%s.%s.%s' "$major" "$minor" "$patch"
}

version_strictly_greater() {
  local candidate="$1" base="$2"
  if [[ "$candidate" == "$base" ]]; then
    return 1
  fi
  local higher
  higher="$(printf '%s\n%s\n' "$base" "$candidate" | sort -V | tail -1)"
  [[ "$higher" == "$candidate" ]]
}

# Infer patch|minor|major from conventional commits in range (since_ref..HEAD).
# Highest severity wins: major > minor > patch.
infer_bump_from_commits() {
  local since_ref="${1:-}"
  local range bodies subjects
  if [[ -n "$since_ref" ]]; then
    range="${since_ref}..HEAD"
  else
    range="HEAD"
  fi

  bodies="$(git log --format='%B' "$range" 2>/dev/null || true)"
  subjects="$(git log --format='%s' "$range" 2>/dev/null || true)"

  if [[ -z "$subjects" ]]; then
    printf 'patch'
    return
  fi

  if printf '%s\n' "$bodies" | grep -Eiq 'BREAKING[[:space:]]+CHANGE'; then
    printf 'major'
    return
  fi
  if printf '%s\n' "$subjects" | grep -Eiq '^[a-zA-Z]+(\([^)]+\))?!:'; then
    printf 'major'
    return
  fi

  if printf '%s\n' "$subjects" | grep -Eiq '^(feat|feature)(\([^)]+\))?:'; then
    printf 'minor'
    return
  fi

  printf 'patch'
}

CURRENT_TAG="$(latest_tag)"
if [[ -z "$CURRENT_TAG" ]]; then
  log "No existing v* tags — first release"
else
  log "Latest tag (local+origin max): $CURRENT_TAG"
fi

if [[ -z "$BUMP" ]]; then
  if [[ -z "$CURRENT_TAG" ]]; then
    BUMP="patch"
    BUMP_SOURCE="auto (first release → v0.1.0)"
  else
    BUMP="$(infer_bump_from_commits "$CURRENT_TAG")"
    BUMP_SOURCE="auto from commits since ${CURRENT_TAG}"
  fi
  log "Bump inferred: $BUMP ($BUMP_SOURCE)"
else
  log "Bump explicit: $BUMP"
fi

NEW_TAG="$(next_version "$CURRENT_TAG" "$BUMP")"
[[ "$NEW_TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "invalid version: $NEW_TAG"
VERSION_NO_V="${NEW_TAG#v}"

if [[ "$BUMP" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] && [[ -n "$CURRENT_TAG" ]]; then
  if ! version_strictly_greater "$NEW_TAG" "$CURRENT_TAG"; then
    fail "explicit tag $NEW_TAG is not strictly greater than latest $CURRENT_TAG (hard refuse; pick a higher version)"
  fi
fi

if git rev-parse -q --verify "refs/tags/$NEW_TAG" >/dev/null; then
  fail "tag already exists locally: $NEW_TAG"
fi
if git ls-remote --tags --refs origin "refs/tags/$NEW_TAG" 2>/dev/null | grep -q .; then
  fail "tag already exists on origin: $NEW_TAG"
fi

# --- CHANGELOG hard gate ------------------------------------------------------

require_changelog() {
  local version_no_v="$1"
  local path="$ROOT/$CHANGELOG_PATH"

  [[ -f "$path" ]] || fail "missing $CHANGELOG_PATH — required for every release"

  # Accept "## 0.3.3" or "## 0.3.3 - 2026-07-29" (optional date). Reject bare Unreleased.
  if ! grep -Eq "^##[[:space:]]+${version_no_v}([[:space:]]+-[[:space:]].*)?[[:space:]]*$" "$path"; then
    fail "CHANGELOG.md has no section for ${version_no_v} (expected heading like '## ${version_no_v} - YYYY-MM-DD'). Move ## Unreleased content under that header, commit, push main, re-run."
  fi

  # Soft preference: version section should not leave user-facing content only under Unreleased
  # when that section is empty of bullets — still require the version heading exists.
  if grep -Eq '^##[[:space:]]+Unreleased[[:space:]]*$' "$path"; then
    # If Unreleased still has content after the heading (next non-empty non-heading line), warn.
    local unreleased_body
    unreleased_body="$(
      awk '
        /^##[[:space:]]+Unreleased[[:space:]]*$/ {in_u=1; next}
        /^##[[:space:]]+/ {in_u=0}
        in_u && NF {print; exit}
      ' "$path"
    )"
    if [[ -n "$unreleased_body" ]]; then
      log "warning: CHANGELOG still has content under ## Unreleased — prefer moving ship notes into ## ${version_no_v}"
    fi
  fi

  log "CHANGELOG.md has section for $version_no_v"
}

log "Checking CHANGELOG for $VERSION_NO_V"
require_changelog "$VERSION_NO_V"

# --- plan ---------------------------------------------------------------------

log "Release plan"
printf '  tag:     %s\n' "$NEW_TAG"
printf '  bump:    %s (%s)\n' "$BUMP" "${BUMP_SOURCE:-explicit}"
printf '  from:    %s\n' "${CURRENT_TAG:-none (first release)}"
printf '  commit:  %s (%s)\n' "$HEAD_SHORT" "$HEAD_SHA"
printf '  branch:  %s\n' "$BRANCH"
printf '  remote:  origin (tag only — no branch push)\n'
printf '  dry-run: %s\n' "$([[ "$DRY_RUN" -eq 1 ]] && echo yes || echo no)"
printf '  changelog: %s section for %s\n' "$CHANGELOG_PATH" "$VERSION_NO_V"
printf '  gh-release: %s\n' "$([[ "$WITH_GH_RELEASE" -eq 1 ]] && echo yes || echo no)"
printf '  gates:\n'
if [[ "$SKIP_TESTS" -eq 0 ]]; then
  printf '    - composer validate --strict\n'
  printf '    - pint --test\n'
  printf '    - composer audit --abandoned=ignore\n'
  if [[ "$WITH_SUITE" -eq 1 ]]; then
    printf '    - pest (full suite — default)\n'
  else
    printf '    - full Pest suite: SKIPPED (--skip-suite hotfix escape)\n'
  fi
else
  printf '    - quality tests: SKIPPED (--skip-tests, dry-run only)\n'
fi

if [[ -n "$CURRENT_TAG" ]]; then
  commit_count="$(git rev-list --count "${CURRENT_TAG}..HEAD" 2>/dev/null || echo 0)"
  printf '  commits since %s:\n' "$CURRENT_TAG"
  local_log="$(git log --oneline "${CURRENT_TAG}..HEAD" 2>/dev/null || true)"
  if [[ -z "$local_log" ]]; then
    printf '    (none — HEAD is at or behind %s)\n' "$CURRENT_TAG"
  else
    printf '%s\n' "$local_log" | head -40 | sed 's/^/    /'
    if [[ "${commit_count}" -gt 40 ]]; then
      printf '    … (%s total)\n' "$commit_count"
    fi
  fi
  if [[ "${commit_count}" -eq 0 && "$ALLOW_EMPTY_RANGE" -eq 0 ]]; then
    fail "empty range: zero commits since ${CURRENT_TAG} — refuse release (pass --allow-empty-range to override)"
  fi
else
  printf '  commits: no prior tag — recent history:\n'
  git log --oneline -20 | sed 's/^/    /' || true
fi

# --- confirm ------------------------------------------------------------------

if [[ "$DRY_RUN" -eq 0 && "$ASSUME_YES" -eq 0 ]]; then
  cat <<EOF

About to run quality gates and release:
  tag:    $NEW_TAG
  commit: $HEAD_SHA ($HEAD_SHORT)
  branch: $BRANCH
  remote: origin (tag push only — Packagist via GitHub tag)

EOF
  read -r -p "Proceed with gates and create/push $NEW_TAG? [y/N] " reply
  case "$reply" in
    y|Y|yes|YES) ;;
    *) fail "aborted" ;;
  esac
fi

# --- quality gates (local only) -----------------------------------------------

run_quality_gates() {
  log "composer validate --strict"
  composer validate --strict

  log "Pint --test"
  ./vendor/bin/pint --test

  log "Composer audit"
  composer audit --abandoned=ignore

  if [[ "$WITH_SUITE" -eq 1 ]]; then
    log "Pest full suite — default ship gate"
    ./vendor/bin/pest
  else
    log "Skipping full Pest suite (--skip-suite hotfix escape). Document the reason."
  fi
}

if [[ "$SKIP_TESTS" -eq 0 ]]; then
  command -v composer >/dev/null || fail "composer not found on PATH"
  [[ -x "$ROOT/vendor/bin/pint" ]] || fail "vendor/bin/pint missing — run composer install"
  if [[ "$WITH_SUITE" -eq 1 ]]; then
    [[ -x "$ROOT/vendor/bin/pest" ]] || fail "vendor/bin/pest missing — run composer install"
  fi
  run_quality_gates
else
  log "Skipping quality tests (--skip-tests, dry-run only)"
fi

log "All quality gates passed"

# --- dry-run exit -------------------------------------------------------------

if [[ "$DRY_RUN" -eq 1 ]]; then
  cat <<EOF

Dry run complete. Would create and push:
  tag:    $NEW_TAG
  commit: $HEAD_SHA ($HEAD_SHORT)
  branch: $BRANCH
  remote: origin (tag only — no branch push)
  changelog: $VERSION_NO_V ✓

Re-run without --dry-run to release.
EOF
  exit 0
fi

# --- pre-tag dirty-tree re-check ----------------------------------------------

if [[ -n "$(git status --porcelain)" ]]; then
  git status --short
  fail "working tree became dirty during gates — refuse to tag"
fi

# --- tag + push ---------------------------------------------------------------

MESSAGE="Release $NEW_TAG

Quality gates:
  - CHANGELOG.md section for ${VERSION_NO_V}
  - composer validate --strict
  - pint --test
  - composer audit --abandoned=ignore"
if [[ "$WITH_SUITE" -eq 1 ]]; then
  MESSAGE+=$'\n  - pest (full suite)'
else
  MESSAGE+=$'\n  - full Pest SKIPPED (--skip-suite hotfix)'
fi
MESSAGE+=$'\nCommit: '"${HEAD_SHA}"

log "Creating annotated tag $NEW_TAG"
git tag -a "$NEW_TAG" -m "$MESSAGE"

log "Pushing tag to origin (branch NOT pushed)"
set +e
git push origin "refs/tags/$NEW_TAG"
push_rc=$?
set -e
if [[ "$push_rc" -ne 0 ]]; then
  log "Tag push failed — deleting local tag $NEW_TAG so re-run is clean"
  git tag -d "$NEW_TAG" || true
  fail "git push origin refs/tags/$NEW_TAG failed (exit $push_rc) — local tag removed"
fi

# Optional GitHub Release (notes from CHANGELOG section)
if [[ "$WITH_GH_RELEASE" -eq 1 ]]; then
  if ! command -v gh >/dev/null 2>&1; then
    log "warning: --with-gh-release set but gh not on PATH — tag is pushed; create the Release manually"
  else
    notes_file="$(mktemp)"
    # Extract body under ## VERSION until next ## heading
    awk -v ver="$VERSION_NO_V" '
      $0 ~ ("^##[[:space:]]+" ver "([[:space:]]+-[[:space:]].*)?[[:space:]]*$") {grab=1; next}
      /^##[[:space:]]+/ {if (grab) exit}
      grab {print}
    ' "$ROOT/$CHANGELOG_PATH" >"$notes_file"
    if [[ ! -s "$notes_file" ]]; then
      printf 'Warp %s\n' "$NEW_TAG" >"$notes_file"
    fi
    log "Creating GitHub Release $NEW_TAG"
    if ! gh release create "$NEW_TAG" --title "$NEW_TAG" --notes-file "$notes_file"; then
      rm -f "$notes_file"
      fail "gh release create failed — tag $NEW_TAG is already on origin; fix notes and re-run gh release create manually"
    fi
    rm -f "$notes_file"
  fi
fi

cat <<EOF

Released $NEW_TAG → origin
  commit: $HEAD_SHORT
  push:   tag only (no branch)
  package: rawphp/warp (Packagist updates from the GitHub tag)

Verify:
  - Packagist: https://packagist.org/packages/rawphp/warp
  - Tag: git ls-remote --tags origin 'refs/tags/${NEW_TAG}'
EOF
if [[ "$WITH_GH_RELEASE" -eq 1 ]]; then
  printf '  - GitHub Release: https://github.com/rawphp/warp/releases/tag/%s\n' "$NEW_TAG"
fi
