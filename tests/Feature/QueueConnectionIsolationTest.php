<?php

declare(strict_types=1);

use RawPHP\Warp\WarmApplicationFactory;

/*
 * The queue manager, once resolved into the warm base (Horizon and many apps
 * touch it at boot), CACHES its queue connections. A DatabaseQueue built in
 * an earlier sandbox keeps that sandbox's DB Connection object; when a later
 * test pushes a queued listener, the stale connection's reconnector calls
 * DatabaseManager::reconnect() — DISCONNECTING the live connection that
 * carries the current test's RefreshDatabase transaction and silently
 * rolling back everything the test wrote. Each sandbox must start with the
 * queue manager's connection cache empty and its app pointed at the sandbox.
 */
/*
 * The FIRST-ever job dispatch in a worker resolves the queue manager through
 * the Bus dispatcher's closure, which captured the BASE app when deferred
 * providers loaded at boot — so the manager (and its first sync connection)
 * is built with the base container, and the dispatched job resolves its
 * dependencies from the BASE, ignoring the current test's mocks. The factory
 * must force-resolve 'queue' into the base at boot so the per-sandbox
 * repoint applies from the very first sandbox.
 */
it('force-resolves the queue manager into the base and repoints it at the current sandbox', function () {
    expect(WarmApplicationFactory::base()->resolved('queue'))->toBeTrue();

    $manager = $this->app->make('queue');

    $managerApp = (function () {
        return $this->app;
    })->call($manager);

    expect($managerApp)->toBe($this->app);
});

it('caches a queue connection on the base-resolved manager', function () {
    $manager = WarmApplicationFactory::base()->make('queue');

    $manager->connection('sync');

    expect(warp_queue_connections($manager))->not->toBeEmpty();
});

it('starts the next sandbox with an empty queue-connection cache', function () {
    $manager = $this->app->make('queue');

    expect(warp_queue_connections($manager))->toBeEmpty();
});

function warp_queue_connections(object $manager): array
{
    return (function (): array {
        return $this->connections;
    })->call($manager);
}
