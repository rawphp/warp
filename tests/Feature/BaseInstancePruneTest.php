<?php

declare(strict_types=1);

use RawPHP\Warp\WarmApplicationFactory;

/*
 * Provider closures registered at boot capture the provider ($this->app =
 * the base), so `singleton(X, fn () => new X($this->app))`-style factories
 * resolve dependencies THROUGH THE BASE no matter which sandbox asked —
 * mid-run the base quietly accumulates SessionManagers, maintenance
 * managers, whatever comes next, and that state is shared with every later
 * sandbox in the worker. Enumerating each service is unbounded; instead the
 * factory snapshots the base's instance list at boot and prunes any
 * additions at every sandbox creation, so a leak dies with the test that
 * caused it.
 */
it('lets a test leak an instance into the base', function () {
    WarmApplicationFactory::base()->instance('warp-mid-run-leak', new stdClass);

    expect(WarmApplicationFactory::base()->bound('warp-mid-run-leak'))->toBeTrue();
});

it('prunes the leaked instance from the base for the next sandbox', function () {
    expect(WarmApplicationFactory::base()->bound('warp-mid-run-leak'))->toBeFalse()
        ->and($this->app->bound('warp-mid-run-leak'))->toBeFalse();
});
