<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Warp\ResetManifest;

it('forgets configured services so the sandbox re-resolves them fresh', function () {
    $base = $this->createClassicApplication();
    $base->singleton('warp.fake', fn () => new stdClass);
    $shared = $base->make('warp.fake');

    $sandbox = clone $base;
    (new ResetManifest)->forget('warp.fake')->apply($sandbox, $base);

    expect($sandbox->make('warp.fake'))->not->toBe($shared)
        ->and($base->make('warp.fake'))->toBe($shared);
});

it('repoints a shared service container reference to the sandbox', function () {
    $base = $this->createClassicApplication();
    $base->singleton('warp.holder', fn ($app) => new class($app)
    {
        public function __construct(protected $container)
        {
        }

        public function exposedContainer()
        {
            return $this->container;
        }
    });
    $base->make('warp.holder');

    $sandbox = clone $base;
    (new ResetManifest)->repoint('warp.holder', 'container')->apply($sandbox, $base);

    expect($sandbox->make('warp.holder')->exposedContainer())->toBe($sandbox)
        ->and($sandbox->make('warp.holder'))->toBe($base->make('warp.holder'));
});

it('skips repoint targets that were never resolved', function () {
    $base = $this->createClassicApplication();
    $base->singleton('warp.lazy', fn () => new stdClass);

    $sandbox = clone $base;

    // Must not force-resolve 'warp.lazy' just to repoint it.
    (new ResetManifest)->repoint('warp.lazy', 'container')->apply($sandbox, $base);

    expect((fn () => array_key_exists('warp.lazy', $this->instances))->call($sandbox))->toBeFalse();
});

it('calls flush methods on resolved shared services', function () {
    $base = $this->createClassicApplication();
    $base->singleton('warp.flushable', fn () => new class
    {
        public int $flushes = 0;

        public function reset(): void
        {
            $this->flushes++;
        }
    });
    $service = $base->make('warp.flushable');

    $sandbox = clone $base;
    (new ResetManifest)->flush('warp.flushable', 'reset')->apply($sandbox, $base);

    expect($service->flushes)->toBe(1);
});

it('runs custom steps with sandbox and base', function () {
    $base = $this->createClassicApplication();
    $sandbox = clone $base;
    $seen = [];

    (new ResetManifest)
        ->add(function ($s, $b) use (&$seen) {
            $seen = [$s, $b];
        })
        ->apply($sandbox, $base);

    expect($seen[0])->toBe($sandbox)->and($seen[1])->toBe($base);
});

it('applies the default manifest to a real booted application', function () {
    $base = $this->createClassicApplication();
    $base->make('db');
    $base->make('router');
    $base->make('events');

    $sandbox = clone $base;
    ResetManifest::default()->apply($sandbox, $base);

    $container = fn (object $service) => (fn () => $this->container)->call($service);
    expect($container($sandbox->make('router')))->toBe($sandbox)
        ->and($container($sandbox->make('events')))->toBe($sandbox)
        ->and((fn () => $this->app)->call($sandbox->make('db')))->toBe($sandbox);
});

it('repoints the http kernel app reference via the default manifest', function () {
    $base = $this->createClassicApplication();
    $base->make(HttpKernelContract::class);

    $sandbox = clone $base;
    ResetManifest::default()->apply($sandbox, $base);

    $app = (fn () => $this->app)->call($sandbox->make(HttpKernelContract::class));

    expect($app)->toBe($sandbox);
});

it('rebinds the gate user resolver to the sandbox auth via the default manifest', function () {
    $base = $this->createClassicApplication();
    $base->make(GateContract::class);

    $sandbox = clone $base;
    ResetManifest::default()->apply($sandbox, $base);

    // The gate resolver captured the BASE app at boot; the manifest must rebind
    // it so the current user is resolved from the sandbox's auth, not the base's.
    $sandboxUser = new stdClass;
    $sandbox->make('auth')->resolveUsersUsing(fn () => $sandboxUser);
    $base->make('auth')->resolveUsersUsing(fn () => null);

    $gate = $sandbox->make(GateContract::class);
    $resolved = (fn () => call_user_func($this->userResolver))->call($gate);

    expect($resolved)->toBe($sandboxUser);
});

it('rebinds the paginator current-page resolver to the sandbox request via the default manifest', function () {
    $base = $this->createClassicApplication();
    // PaginationServiceProvider registers the resolvers against the base app at
    // boot, closing over the base request (which carries no ?page).
    $base->instance('request', Request::create('/things'));

    $sandbox = clone $base;
    ResetManifest::default()->apply($sandbox, $base);

    // Give the sandbox its own request on a later page. Without the manifest
    // rebind the static resolver still reads the base request and returns 1.
    $sandbox->instance('request', Request::create('/things?page=3'));

    expect(Paginator::resolveCurrentPage())->toBe(3);
});
