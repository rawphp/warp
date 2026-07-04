<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Warp\ResetManifest;
use Warp\WarmApplicationFactory;

it('boots the base application exactly once across sandboxes', function () {
    $manifest = ResetManifest::default();
    $boots = 0;
    $create = function () use (&$boots) {
        $boots++;

        return $this->createClassicApplication();
    };

    WarmApplicationFactory::scrap();
    $s1 = WarmApplicationFactory::sandbox($create, $manifest);
    $s2 = WarmApplicationFactory::sandbox($create, $manifest);

    expect($boots)->toBe(1)
        ->and($s1)->not->toBe($s2)
        ->and($s1)->not->toBe(WarmApplicationFactory::base());
});

it('does not leak instance bindings between sandboxes', function () {
    $manifest = ResetManifest::default();
    $create = fn () => $this->createClassicApplication();

    $s1 = WarmApplicationFactory::sandbox($create, $manifest);
    $s1->instance('warp.leak', 'leaked');

    $s2 = WarmApplicationFactory::sandbox($create, $manifest);

    expect($s2->bound('warp.leak'))->toBeFalse();
});

it('gives each sandbox its own config repository', function () {
    $manifest = ResetManifest::default();
    $create = fn () => $this->createClassicApplication();

    $s1 = WarmApplicationFactory::sandbox($create, $manifest);
    $s1['config']->set('app.name', 'mutated-by-test');

    $s2 = WarmApplicationFactory::sandbox($create, $manifest);

    expect($s2['config']->get('app.name'))->not->toBe('mutated-by-test')
        ->and($s2['config'])->not->toBe($s1['config'])
        ->and(WarmApplicationFactory::base()['config']->get('app.name'))->not->toBe('mutated-by-test');
});

it('anchors the sandbox as the current container, facade root, and app instance', function () {
    $manifest = ResetManifest::default();
    $sandbox = WarmApplicationFactory::sandbox(fn () => $this->createClassicApplication(), $manifest);

    expect(Container::getInstance())->toBe($sandbox)
        ->and(Facade::getFacadeApplication())->toBe($sandbox)
        ->and($sandbox->make('app'))->toBe($sandbox)
        ->and($sandbox->make(Container::class))->toBe($sandbox);
});

it('shares the database manager with the base so connections persist across tests', function () {
    $manifest = ResetManifest::default();
    $sandbox = WarmApplicationFactory::sandbox(fn () => $this->createClassicApplication(), $manifest);

    expect($sandbox->make('db'))->toBe(WarmApplicationFactory::base()->make('db'));
});

it('forgets stateful services so each sandbox re-resolves them fresh', function () {
    $manifest = ResetManifest::default();
    $create = fn () => $this->createClassicApplication();

    WarmApplicationFactory::sandbox($create, $manifest);
    $baseCache = WarmApplicationFactory::base()->make('cache'); // simulate boot-time resolution

    $s1 = WarmApplicationFactory::sandbox($create, $manifest);
    expect($s1->make('cache'))->not->toBe($baseCache);

    $s1->make('cache')->store('array')->put('warp-key', 'v1');

    $s2 = WarmApplicationFactory::sandbox($create, $manifest);
    expect($s2->make('cache')->store('array')->get('warp-key'))->toBeNull();
});
