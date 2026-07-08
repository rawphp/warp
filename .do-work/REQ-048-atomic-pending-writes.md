# REQ-048: Atomic pending-batch writes and tolerant, glob-safe pending discovery

**UR:** UR-011
**Status:** backlog
**Created:** 2026-07-09
**Layer:** package
**Entry point:**
**Terminal state:**
**Parent:** REQ-046
**Closure proof:**
**Criteria approved:** agent-drafted
**Priority:** 3
**Size:** M
**Files:** src/Timing/TimingStore.php, tests/Unit/Timing/TimingStoreTest.php
**Depends on:**

## Task

Three fixes to `TimingStore` pending-batch I/O:

1. **Atomic writes** (finding #7): `writePending()` (src/Timing/TimingStore.php:37-40) must write to a `.tmp` file in the same directory and `rename()` into place, so a concurrent reader can never observe a truncated batch. Fix or remove the "single atomic write" comment at line 25 so it is true.
2. **Never destroy undecodable batches** (finding #7): `mergePending()` currently `unlink()`s any pending file whose JSON fails to decode (line 75). Change to skip (leave in place) undecodable files and emit a stderr warning naming the file, so a racing/corrupt batch is never silently destroyed.
3. **Glob-safe discovery** (finding #8): replace `glob($this->dir.'/pending/*.json')` (line 58) with a directory-listing approach (`scandir`/`DirectoryIterator` filtered on the `.json` suffix) that works when the timings dir path contains glob metacharacters (`[`, `]`, `*`, `?`).

## Context

Review findings #7 and #8. `writePending()` uses plain `file_put_contents` with no tmp+rename; `mergePending()` reads with a bare `file_get_contents` and unlinks on decode failure — a reader racing a writer's shutdown flush permanently destroys that worker's batch. Separately, `glob()` on a path like `/home/ci/job[1]/app/.warp/timings` parses `[1]` as a character class, returns `[]`, and pending batches are silently never merged. Clean break on any format details is allowed (decisions.md 2026-07-05).

## Acceptance Criteria

- [ ] `writePending()` writes via tmp-file + `rename()`; a test proves no non-`.tmp` partial file is ever visible under `pending/` (e.g. by asserting the final file decodes even when written concurrently-style, and that no stray `.tmp` remains after a successful write).
- [ ] A pending file containing invalid JSON is left on disk after `mergePending()`, a stderr warning names it, and valid sibling batches still merge.
- [ ] Pending discovery finds batches when the store directory path contains `[`, `]`, `*`, or `?` (test creates a store under a path like `base[1]/timings` and asserts merge picks the batch up).
- [ ] Existing TimingStore tests still pass unchanged except where they pinned the old unlink-on-decode-failure behaviour.

## Verification Steps

> Execute these after implementation to confirm the feature actually works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest tests/Unit/Timing/TimingStoreTest.php`
   - Expected: new atomicity, tolerant-decode, and glob-metachar tests pass.
2. **test** `./vendor/bin/pest`
   - Expected: full suite green.

## Integration

**Reachability:** `TimingStore::writePending()` is called from `TimingCollector::flush()` (src/Timing/TimingCollector.php) on every recording run; `mergePending()` runs inside `TimingStore::load()` (src/Timing/TimingStore.php:90) — no new surface, hardened existing surface.

**Data dependencies:** Reads/writes `pending/*.json` batch files and `timings.json` under the store directory (default `.warp/timings`).

**Service dependencies:** None beyond PHP filesystem functions; interacts with the same `TimingStore` internals REQ-049/REQ-050 modify (hence the dependency chain).
