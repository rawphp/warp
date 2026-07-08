# Ideate — UR-010

**Reviewed:** 2026-07-09

## Explorer — Assumptions & Perspectives

- The brief in `UR-010/input.md` stores only the plan path, so future workers depend on `docs/superpowers/plans/2026-07-08-s3-timing-capture-sharding.md` remaining present and unchanged; if that file is edited after capture, audit trails and worker context could drift from the REQ set. This is triggered by the `## Request` body containing only an absolute filesystem path rather than the plan text.
- Parallel Pest timing capture affects three stakeholder groups at once: package users need no-op safety when `WARP_TIMINGS` is unset, CI maintainers need deterministic shard agreement, and do-work workers need isolated REQs that do not fight over `phpunit.xml` or shared timing artifacts. A worker that focuses only on the package API could pass unit tests while breaking shell substitution or CI artifact portability. This is triggered by the plan's combined extension, CLI, and bench scope.
- The plan assumes every recorded timing belongs to a stable project-relative test file; if file attribution fails for Pest-generated tests or files outside the invocation root, the timing artifact becomes non-portable across CI machines. This is triggered by the spike fact that `TestMethod::file()` is wrong for Pest tests and by the global constraint to skip unattributable tests instead of guessing.
- The plan assumes `warp shard` remains machine-clean under every error path; if diagnostics, warnings, or usage text leak to stdout, `pest $(warp shard ...)` can receive non-file tokens and execute the wrong set. This is triggered by the global constraint that shard output writes only file lists to stdout.

## Challenger — Risks & Edge Cases

- File-replacement semantics can delete useful data during partial runs: if a developer runs `WARP_TIMINGS=1 ./vendor/bin/pest --filter SomeCase`, the fresh batch for that file may supersede the full-file history with a filtered subset. This is triggered by the `TimingStore` requirement that a batch supersedes all previous entries for every covered file.
- Deterministic sharding can still diverge if absolute and relative paths are mixed, if missing timings use different fallback weights, or if equal-load tie breaks depend on filesystem iteration order. This would cause CI shard machines to disagree even with the same artifact. This is triggered by the requirement that every shard computes the whole plan independently without coordination.
- Registering `TimingExtension` in `phpunit.xml` before the class exists can break the whole suite before normal tests run; if REQ-040's red/green sequence is not followed carefully, unrelated REQs may look broken because PHPUnit refuses to boot. This is triggered by Task 5's instruction to register the extension first and then implement the class.
- The package declares only `"php": "^8.4"` in runtime `require`, while the new extension references PHPUnit event classes supplied by the host app; if those references are loaded eagerly outside a test runner, consumers without PHPUnit on the autoload path could see fatal errors. This is triggered by the global constraint allowing PHPUnit event classes in `src/` without adding composer runtime dependencies.
- The bench harness records `.warp/timings` in the target app and then removes `.warp`; if a worker runs it from the wrong cwd or against a real consuming app with valuable local artifacts, cleanup could delete more state than intended. This is triggered by Task 9's `rm -rf .warp` verification step and the shell wrapper's `cd "$APP"` behavior.

## Connector — Links & Reuse

- `WarpMode::timingsEnabled()` should mirror the existing strict env parsing in `src/WarpMode.php`; using case-insensitive parsing would contradict the prior `WARP_MODE` and `WARP_DB` switch pattern and could make documented falsey values pass unexpectedly. This is triggered by Task 1's accepted truthy list and the existing `enabled()` / `databaseEnabled()` methods.
- The timing store should reuse `RawPHP\Warp\Db\Dirs` from `src/Db/Dirs.php`; inventing a second filesystem helper could produce inconsistent directory creation, cleanup, and `[warp]` error behavior. This is triggered by Task 2's explicit dependency on `Dirs` and the existing S2 snapshot tooling.
- `phpunit.xml` already has a clean top-level test-suite layout but no `<extensions>` block; the TimingExtension REQ should add the smallest valid XML change so later REQs touching integration tests do not inherit unrelated config churn. This is triggered by Task 5's extension registration and the current `phpunit.xml` structure.
- The REQ set follows the recorded UR-001 and UR-006 standing decision that reviewed plan tasks map 1:1 to REQs and the plan remains authoritative; reshaping UR-010 into path-unit child REQs now would fight the project's existing execution pattern. This is triggered by `.do-work/decisions.md` entries for UR-001, UR-006, and UR-010.
- Documentation should connect S3 to the existing README's S1/S2 progression rather than present it as a separate tool; otherwise users may miss that timing capture is the next orchestration layer after warm workers and snapshot DB provisioning. This is triggered by the README's current measured-results, usage, and S2 sections plus Task 10's new S3 documentation requirement.

## Summary

UR-010 remains execution-ready, and the existing ten-REQ split matches the project's prior plan-driven capture pattern. The highest-risk points to keep visible during implementation are artifact determinism under parallel and partial runs, Pest file attribution, PHPUnit extension load safety, and preserving shell-clean CLI behavior for CI.
