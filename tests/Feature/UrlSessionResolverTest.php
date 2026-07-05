<?php

declare(strict_types=1);

use Warp\WarmApplicationFactory;

/*
 * RoutingServiceProvider's 'url' extender sets the UrlGenerator's session
 * and key resolvers as closures over the PROVIDER ($this->app = the app that
 * registered it — the warm base). Every sandbox's UrlGenerator then reads
 * the session (and app keys) from the BASE: the first failed FormRequest
 * validation (redirect ->previous()) resolves a SessionManager INTO the
 * base, silently sharing session state across every later sandbox in the
 * worker. The manifest must re-point those resolvers at the sandbox.
 */
it('resolves the url session resolver against the sandbox, not the base', function () {
    $marker = new stdClass;
    $this->app->instance('session', $marker);

    $url = $this->app->make('url');

    $resolved = (function () {
        return call_user_func($this->sessionResolver);
    })->call($url);

    expect($resolved)->toBe($marker)
        ->and(WarmApplicationFactory::base()->resolved('session'))->toBeFalse();
});
