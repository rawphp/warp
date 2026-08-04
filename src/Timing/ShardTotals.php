<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

/**
 * Pure decision of which file totals a shard plan may use.
 *
 * No I/O, no env reads — CLI supplies stored/canonical roots, totals, and the
 * WARP_STRICT_ROOT flag. Empty {@see $totals} means count-balanced sharding.
 *
 * @internal Used by the shard CLI; not host-facing.
 */
final class ShardTotals
{
    /**
     * @param  array<string, float>  $totals  File => ms to feed the sharder; empty = count-balanced
     * @param  string|null  $message  Optional stderr diagnostic (warning or hard-fail reason)
     * @param  int|null  $hardFailExit  Non-null = abort shard with this exit code
     */
    public function __construct(
        public readonly array $totals,
        public readonly ?string $message = null,
        public readonly ?int $hardFailExit = null,
    ) {}

    /**
     * @param  array<string, float>  $totals
     * @param  list<string>  $files  Canonical discovered test files for this shard invocation
     */
    public static function resolve(
        ?string $storedRoot,
        string $canonicalRoot,
        array $totals,
        array $files,
        string $dirLabel,
        bool $strictRoot,
    ): self {
        $keysMatch = $totals !== [] && array_intersect_key($totals, array_flip($files)) !== [];

        if ($storedRoot !== null && $storedRoot !== $canonicalRoot) {
            // Root-mismatch policy: a differing absolute root is metadata only —
            // per-file timing keys are stored relative, so when they still match
            // discovered files the timings are usable on this checkout path
            // (committed/shared-baseline workflow). If no key matches, degrade to
            // count-balanced. WARP_STRICT_ROOT restores hard-fail when keys match.
            if ($keysMatch) {
                if ($strictRoot) {
                    return new self(
                        totals: $totals,
                        message: "[warp] timings root mismatch: recorded against '{$storedRoot}' but this shard resolves keys against '{$canonicalRoot}' - WARP_STRICT_ROOT is set, so this is a hard error; re-record timings from the same config dir or pass the matching --configuration",
                        hardFailExit: 2,
                    );
                }

                return new self(
                    totals: $totals,
                    message: "[warp] timings root differs: recorded against '{$storedRoot}' but this shard resolves keys against '{$canonicalRoot}' - recorded keys still match discovered files, so the timings are portable; using them (set WARP_STRICT_ROOT=1 to treat this as an error)",
                );
            }

            return new self(
                totals: [],
                message: "[warp] timings root mismatch: recorded against '{$storedRoot}' but this shard resolves keys against '{$canonicalRoot}' - no recorded key matches a discovered file (stale or foreign artifact); sharding count-balanced",
            );
        }

        if ($totals === []) {
            return new self(
                totals: [],
                message: "[warp] no recorded timings under {$dirLabel} - sharding count-balanced",
            );
        }

        if (! $keysMatch) {
            return new self(
                totals: $totals,
                message: '[warp] recorded timings match no discovered file - likely path-form or stale-artifact mismatch; sharding count-balanced',
            );
        }

        return new self(totals: $totals);
    }
}
