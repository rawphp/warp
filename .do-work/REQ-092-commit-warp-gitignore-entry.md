# REQ-092: Commit the .warp/ gitignore entry

**UR:** UR-016
**Status:** backlog
**Created:** 2026-07-10
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:**
**Criteria approved:** agent-drafted
**Size:** S
**Files:** .gitignore
**Depends on:**

## Task

Commit the `.warp/` ignore entry to `.gitignore`. The entry currently exists only as an uncommitted working-tree edit (HEAD's `.gitignore` ends at `/composer.lock`), and it was added without a trailing newline. Add `.warp/` as a committed line with a proper trailing newline.

## Context

Finding 8 (UR-016): the feature's default artifact dir is `{cwd}/.warp/timings` (TimingStore::fromEnv), so any `WARP_TIMINGS=1` run creates untracked `.warp/timings/*.json` in every checkout. The ignore entry protecting against this lives only in one machine's working tree and will be lost on any other clone. Workers branch worktrees from HEAD, which lacks the entry — this REQ makes it durable.

## Acceptance Criteria

- [ ] `git show HEAD:.gitignore` contains a `.warp/` line after this REQ's commit
- [ ] The file ends with a trailing newline (`tail -c 1 .gitignore` is `\n`)
- [ ] `git status` shows no untracked files after running `WARP_TIMINGS=1 ./vendor/bin/pest --filter=WarpModeTest` in a scratch checkout state (timings artifacts are ignored)

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **runtime** `mkdir -p .warp/timings && touch .warp/timings/probe.json && git status --porcelain -- .warp/ && rm -rf .warp`
   - Expected: `git status --porcelain -- .warp/` prints nothing (the directory is ignored); cleanup removes the probe
2. **test** `tail -c 1 .gitignore | od -c | head -1`
   - Expected: last byte is `\n`
