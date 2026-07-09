# REQ-063: Fix SnapshotStore promote() unimported RuntimeException


**UR:** UR-012
**Status:** done
**Created:** 2026-07-09
**Layer:** none
**Entry point:**
**Terminal state:**
**Parent:**
**Closure proof:** checkpoint_log:passed all 2 verification checkpoints passed; commit:9e71f88
**Criteria approved:** agent-drafted
**Priority:** 2
**Size:** S
**Files:** src/Db/SnapshotStore.php, tests/Unit/Db/SnapshotStoreTest.php
**Depends on:**

## Task

`SnapshotStore::promote()` throws `RuntimeException` unqualified, but the FileLock refactor (REQ-047) removed `use RuntimeException;` from the file (it was replaced with `use RawPHP\Warp\Support\FileLock;`). In the `RawPHP\Warp\Db` namespace the bare name now resolves to the non-existent `RawPHP\Warp\Db\RuntimeException`. Restore the import (`use RuntimeException;`) or fully-qualify the throw as `\RuntimeException`, so a failed snapshot `rename()` raises the intended catchable exception instead of a fatal `Error: Class not found`.

## Context

Code-review finding #2 (CONFIRMED, self-verified against the diff). This is a regression self-inflicted by UR-011/REQ-047's FileLock extraction — the diff shows `-use RuntimeException;` with the throw at `src/Db/SnapshotStore.php:46` left intact. The failure path is currently untested (`SnapshotStoreTest` covers only the success case), so the bug is fully latent: any promote failure (cross-device move, permission denied, target exists) becomes an uncatchable fatal Error and the `[warp] failed to promote snapshot ...` diagnostic is lost. Every other `RawPHP\Warp\Db` class imports `RuntimeException` explicitly — match that convention.

## Acceptance Criteria

- [x] `SnapshotStore::promote()` throws a catchable `\RuntimeException` (not a fatal `Error`) when the underlying `rename()` fails, carrying the message `[warp] failed to promote snapshot <staging>`.
- [x] The fix uses the same import convention as sibling `RawPHP\Warp\Db` classes (explicit `use RuntimeException;`) OR a leading-backslash `\RuntimeException`; no other behaviour changes.
- [x] A new test in `SnapshotStoreTest` exercises the promote-failure path and asserts a `RuntimeException` is thrown (previously untested).

## Verification Steps

> Execute these after implementation to confirm the fix works at runtime. Each must pass before committing.

1. **test** `./vendor/bin/pest --filter=SnapshotStore` — Expected: all SnapshotStore tests pass, including the new promote-failure test that asserts `RuntimeException` (not `Error`) is thrown.
2. **runtime** In a scratch script, call `promote()` on a nonexistent/uncreatable staging dir and confirm the thrown class is `RuntimeException` and is caught by `catch (\RuntimeException $e)`. Expected: caught cleanly; message contains `failed to promote snapshot`.

## Outputs

- src/Db/SnapshotStore.php — Restored explicit RuntimeException import for SnapshotStore::promote().
- tests/Unit/Db/SnapshotStoreTest.php — Added failure-path test asserting promote() throws a catchable RuntimeException.
