<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Route::getController() caches the resolved controller INSTANCE on the
 * Route object — which warm mode shares across sandboxes. A controller
 * resolved in one test (with that test's container bindings, e.g. a
 * $this->mock() instance injected) would then serve every later test
 * hitting the same route, ignoring their bindings entirely. Octane flushes
 * this per request; Warp must flush it per sandbox.
 */
class WarpProbeController
{
    public function show(): string
    {
        return 'ok';
    }
}

$state = new stdClass;

it('resolves and caches a route controller in the first sandbox', function () use ($state) {
    $route = Route::get('/warp-controller-probe', [WarpProbeController::class, 'show']);

    $state->controllerId = spl_object_id($route->getController());

    expect($state->controllerId)->toBeGreaterThan(0);
});

it('resolves a fresh controller instance in the next sandbox', function () use ($state) {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->uri() === 'warp-controller-probe');

    expect($route)->not->toBeNull()
        ->and(spl_object_id($route->getController()))->not->toBe($state->controllerId);
});
