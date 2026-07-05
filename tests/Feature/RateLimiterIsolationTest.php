<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use RawPHP\Warp\WarmApplicationFactory;

/*
 * Apps register named limiters at boot (RateLimiter::for in a provider),
 * resolving the RateLimiter singleton into the warm base with the BOOT
 * cache store captured inside it. Sandboxes get fresh cache repositories,
 * but the shared limiter keeps counting into the boot store — so throttle
 * hits accumulate across every test in the worker and requests start
 * failing with 429s. The manifest must swap the limiter's cache per
 * sandbox while PRESERVING the boot-registered named limiters.
 *
 * Resolving through WarmApplicationFactory::base() below reproduces the
 * boot-time resolution: the singleton lands in the base's instances array,
 * so every later sandbox clone shares it.
 */
it('hits a base-resolved rate limiter in the first sandbox', function () {
    $limiter = WarmApplicationFactory::base()->make(RateLimiter::class);
    $limiter->for('warp-named', fn (): Limit => Limit::perMinute(2));

    $limiter->hit('warp-key', 60);
    $limiter->hit('warp-key', 60);
    $limiter->hit('warp-key', 60);

    expect($limiter->attempts('warp-key'))->toBe(3)
        ->and($limiter->tooManyAttempts('warp-key', 2))->toBeTrue();
});

it('starts the next sandbox with a clean slate', function () {
    // The previous test's limiter was resolved into the base MID-RUN, so the
    // instance-prune drops it entirely (a leak dies with its test); this
    // sandbox resolves a fresh limiter with zeroed counters. A limiter
    // resolved at BOOT (RateLimiter::for in a provider) instead survives in
    // the boot snapshot and gets its cache swapped per sandbox by the
    // manifest, preserving boot-registered named limiters.
    $limiter = $this->app->make(RateLimiter::class);

    expect($limiter->attempts('warp-key'))->toBe(0)
        ->and(WarmApplicationFactory::base()->resolved(RateLimiter::class))->toBeFalse();
});
