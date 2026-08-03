<?php

declare(strict_types=1);

namespace RawPHP\Warp\Warm;

use Illuminate\Foundation\Application;
use RawPHP\Warp\Sentinel\HermeticitySentinel;
use RawPHP\Warp\WarmApplicationFactory;

/**
 * Process-global warm runtime: base app + boot fingerprints + hermeticity probe.
 *
 * Published as a single optional value so a throw mid-boot cannot leave a live
 * base with a null snapshot (partial process state is worse than no base).
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
