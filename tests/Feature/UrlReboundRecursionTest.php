<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlGenerator as UrlGeneratorContract;
use Warp\WarmApplicationFactory;

/*
 * RoutingServiceProvider's 'url' extender registers a 'routes' rebinding
 * callback on whichever container resolves 'url'. If the BASE ever resolves
 * 'url' (boot-time or through any base-captured closure), every sandbox
 * clone inherits that callback — and because the manifest forgets 'url' per
 * sandbox, the next url resolution loops forever: build url → instance
 * 'routes' → fire inherited callback → $app['url'] (still mid-build) →
 * build url → ... until memory or stack dies, taking the whole paratest
 * worker with it. Sandboxes must start without inherited 'routes' rebound
 * callbacks; url re-registers its own on rebuild.
 */
it('resolves url on the base, registering a routes rebinding there', function () {
    WarmApplicationFactory::base()->make('url');

    $callbacks = (function () {
        return $this->reboundCallbacks;
    })->call(WarmApplicationFactory::base());

    expect($callbacks['routes'] ?? [])->not->toBeEmpty();
});

it('gives the next sandbox no inherited routes rebound callbacks and a terminating url resolution', function () {
    $callbacks = (function () {
        return $this->reboundCallbacks;
    })->call($this->app);

    expect($callbacks['routes'] ?? [])->toBeEmpty();

    $this->app->forgetInstance('url');

    expect($this->app->make('url'))->toBeInstanceOf(UrlGeneratorContract::class);
});
