<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Warp\WarmApplicationFactory;

$warm = new stdClass;

it('runs the first warm test inside a sandbox anchored as the current container', function () use ($warm) {
    $warm->boots = WarmApplicationFactory::bootCount();

    expect($this->usingWarmSandbox())->toBeTrue()
        ->and(Container::getInstance())->toBe($this->app)
        ->and($this->app)->not->toBe(WarmApplicationFactory::base());
});

it('reuses the warm base for subsequent tests without rebooting', function () use ($warm) {
    expect(WarmApplicationFactory::bootCount())->toBe($warm->boots)
        ->and($this->usingWarmSandbox())->toBeTrue();
});

it('pollutes container and config to set up the leak observation', function () {
    app()->instance('warp.polluted', 'yes');
    config(['app.name' => 'polluted']);

    expect(app('warp.polluted'))->toBe('yes')
        ->and(config('app.name'))->toBe('polluted');
});

it('observes no leakage from the previous test', function () {
    expect(app()->bound('warp.polluted'))->toBeFalse()
        ->and(config('app.name'))->not->toBe('polluted');
});

it('runs group-isolated tests on a fresh classic application', function () use ($warm) {
    expect($this->usingWarmSandbox())->toBeFalse()
        ->and(WarmApplicationFactory::bootCount())->toBe($warm->boots);
})->group('warp-isolated');

it('returns to the warm sandbox after an isolated test', function () use ($warm) {
    expect($this->usingWarmSandbox())->toBeTrue()
        ->and(WarmApplicationFactory::bootCount())->toBe($warm->boots);
});
