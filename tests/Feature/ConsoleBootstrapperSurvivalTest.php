<?php

declare(strict_types=1);

use Illuminate\Console\Application as Artisan;

/*
 * Laravel's per-test teardown (InteractsWithTestCaseLifecycle) calls
 * Artisan::forgetBootstrappers(), wiping the PROCESS-STATIC list of console
 * command registrars. Classic mode survives because the next test re-boots
 * the app and re-registers them; a warm base boots once, so without
 * restoration the first teardown leaves every later sandbox unable to build
 * a console application that knows any commands
 * ("The command \"migrate:fresh\" does not exist").
 */
$state = new stdClass;

it('captures the command set a fresh console application gets before any teardown', function () use ($state) {
    $state->bootstrappers = count(warp_test_bootstrappers());
    $state->commands = array_keys(warp_test_artisan($this->app)->all());

    expect($state->bootstrappers)->toBeGreaterThan(0)
        ->and($state->commands)->not->toBeEmpty();
});

it('builds a console application with the same command set after teardown wiped the statics', function () use ($state) {
    // The previous test's teardown ran Artisan::forgetBootstrappers(); the
    // warm factory must have restored the base's registrars for this sandbox.
    expect(count(warp_test_bootstrappers()))->toBe($state->bootstrappers)
        ->and(array_keys(warp_test_artisan($this->app)->all()))->toBe($state->commands);
});

function warp_test_bootstrappers(): array
{
    return (new ReflectionProperty(Artisan::class, 'bootstrappers'))->getValue();
}

function warp_test_artisan($app): Artisan
{
    return new Artisan($app, $app->make('events'), $app->version());
}
