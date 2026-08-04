<?php

declare(strict_types=1);

namespace RawPHP\Warp\Warm;

use Illuminate\Foundation\Application;
use RawPHP\Warp\Sentinel\HermeticitySentinel;
use RawPHP\Warp\WarmApplicationFactory;

/**
 * Process-global warm runtime: base app + boot fingerprints + hermeticity probe.
 *
 * Held as one optional factory static so publish is all-or-nothing at the
 * factory level (no base without snapshot/sentinel; mid-boot throw → session
 * stays null). Factory atomicity only — not process-level cleanup of a
 * half-booted Laravel app after createClassicApplication() has run.
 *
 * @internal Package warm-engine plumbing; not host-facing. Hosts use
 *           {@see WarmApplicationFactory} / the TestCase trait.
 */
final class WarmSession
{
    public function __construct(
        public readonly Application $base,
        public readonly BootSnapshot $snapshot,
        public readonly HermeticitySentinel $sentinel,
    ) {}
}
