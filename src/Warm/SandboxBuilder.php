<?php

declare(strict_types=1);

namespace RawPHP\Warp\Warm;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use RawPHP\Warp\ResetManifest;
use RawPHP\Warp\WarmApplicationFactory;

/**
 * Build a per-test sandbox from a warm base: shallow clone, re-anchor
 * container/config, apply the reset manifest. No process-global session
 * state — that stays on {@see WarmApplicationFactory}.
 *
 * @internal Package warm-engine plumbing; not host-facing.
 */
final class SandboxBuilder
{
    public static function from(Application $base, ResetManifest $manifest): Application
    {
        $sandbox = clone $base;

        // The clone's instances array still anchors 'app'/Container at the base;
        // mirror Application::registerBaseBindings() for the sandbox and give it
        // its own config repository (array items copy by value on clone).
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        $sandbox->instance('config', clone $sandbox->make('config'));

        Container::setInstance($sandbox);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($sandbox);

        $manifest->apply($sandbox, $base);

        return $sandbox;
    }
}
