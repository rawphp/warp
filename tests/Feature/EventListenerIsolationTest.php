<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\EventFake;

class WarpBootProbeModel extends Model
{
    protected static function booted(): void
    {
        static::saving(fn (): bool => true);
    }

    public static function isBooted(): bool
    {
        return isset(static::$booted[static::class]);
    }
}

class WarpProbeObserver
{
    public function saving(WarpObservedModel $model): bool
    {
        return true;
    }
}

#[Illuminate\Database\Eloquent\Attributes\ObservedBy(WarpProbeObserver::class)]
class WarpObservedModel extends Model {}

/*
 * The event dispatcher is shared across sandboxes (repointed, same object),
 * so an Event::listen() registered inside one test would otherwise persist
 * for every later test in the worker — e.g. a test simulating a payment
 * failure by throwing from a listener poisons every subsequent signup.
 * Classic mode discards the dispatcher with the per-test app. Each sandbox
 * must start from the dispatcher's boot-time listener state.
 */
it('registers a test-local event listener in the first sandbox', function () {
    $fired = new stdClass;
    $fired->count = 0;

    Event::listen('warp-leak-probe', function () use ($fired): void {
        $fired->count++;
    });

    Event::dispatch('warp-leak-probe');

    expect($fired->count)->toBe(1);
});

it('does not see the previous test listener in the next sandbox', function () {
    expect(Event::getRawListeners())->not->toHaveKey('warp-leak-probe');

    Event::dispatch('warp-leak-probe');
});

/*
 * Eloquent models boot lazily, ONCE per process, registering their model
 * event listeners (creating/created/...) on the shared dispatcher at that
 * moment — possibly long after base boot. Restoring the dispatcher to its
 * boot snapshot must not orphan them: the booted-model memo is rolled back
 * with it, so a model first used in an earlier test re-boots in the next
 * sandbox and re-registers its listeners (exactly what a classic cold boot
 * does via Model::clearBootedModels()).
 */
/*
 * Event::fake() swaps the Eloquent STATIC dispatcher too
 * (Model::setEventDispatcher($fake)). Classic mode resets it on every cold
 * boot via DatabaseServiceProvider; a warm sandbox must do the same or the
 * previous test's fake — still swallowing its faked events — drives every
 * later test's model events.
 */
it('fakes events, which also swaps the Eloquent static dispatcher', function () {
    Event::fake(['warp-faked-event']);

    expect(Model::getEventDispatcher())
        ->toBeInstanceOf(EventFake::class);
});

it('gives the next sandbox a real Eloquent static dispatcher again', function () {
    expect(Model::getEventDispatcher())
        ->not->toBeInstanceOf(EventFake::class);
});

it('boots a model mid-test and observes its model events', function () {
    $model = new WarpBootProbeModel;

    expect(Event::getRawListeners())->toHaveKey('eloquent.saving: '.WarpBootProbeModel::class);
});

it('still observes model events for that model in the next sandbox', function () {
    expect(WarpBootProbeModel::isBooted())->toBeFalse();

    new WarpBootProbeModel;

    expect(Event::getRawListeners())->toHaveKey('eloquent.saving: '.WarpBootProbeModel::class);
});

/*
 * bootHasEvents() registers attribute observers (#[ObservedBy]) through
 * Model::whenBooted(), which APPENDS to the $bootedCallbacks static on every
 * re-boot. Rolling back only $booted makes each sandbox's re-boot stack one
 * more "register the observers" callback — so test N fires each observer N
 * times (observed in the wild as storage counters incrementing N-fold).
 * $bootedCallbacks must be rolled back together with $booted, exactly as the
 * framework's clearBootedModels() clears both.
 */
it('registers an attribute observer exactly once in the first sandbox', function () {
    new WarpObservedModel;

    expect(Event::getRawListeners()['eloquent.saving: '.WarpObservedModel::class] ?? [])->toHaveCount(1);
});

it('still registers the attribute observer exactly once in the next sandbox', function () {
    new WarpObservedModel;

    expect(Event::getRawListeners()['eloquent.saving: '.WarpObservedModel::class] ?? [])->toHaveCount(1);
});
