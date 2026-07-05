<?php

declare(strict_types=1);

use Illuminate\Testing\ParallelTesting;
use Warp\WarmApplicationFactory;

/*
 * Laravel's ParallelTestingServiceProvider registers per-test callbacks
 * (cache prefix, compiled-view path, test-database switch) as closures over
 * the provider instance. That provider's $app is whichever application booted
 * it — the warm BASE. Unless the manifest repoints it, every paratest worker
 * test writes its TEST_TOKEN suffixes through that reference into the base
 * config, corrupting the warm base and failing the whole `--parallel` run.
 */
it('lands parallel-testing config writes on the sandbox, not the warm base', function () {
    $base = WarmApplicationFactory::base();
    $pristinePrefix = $base->make('config')->get('cache.prefix');

    $_SERVER['LARAVEL_PARALLEL_TESTING'] = 1;
    $_SERVER['TEST_TOKEN'] = '7';

    try {
        $this->app->make(ParallelTesting::class)->callSetUpTestCaseCallbacks($this);
    } finally {
        unset($_SERVER['LARAVEL_PARALLEL_TESTING'], $_SERVER['TEST_TOKEN']);
    }

    expect(config('cache.prefix'))->toEndWith('test_7_')
        ->and($base->make('config')->get('cache.prefix'))->toBe($pristinePrefix);
});
