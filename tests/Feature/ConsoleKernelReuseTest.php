<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

/*
 * The shared console kernel caches its Artisan console application the first
 * time a command runs. That cached instance — and every Command object inside
 * it — holds the sandbox that built it, which Laravel's teardown has since
 * flush()ed into a dead container. A later test invoking any artisan command
 * would then resolve dependencies through the dead container
 * ("Target class [env] does not exist"). Each sandbox must get a console
 * application bound to itself.
 */
it('runs an artisan command in the first sandbox', function () {
    expect($this->app->make(ConsoleKernel::class)->call('env'))->toBe(0);
});

it('runs an artisan command again after the first sandbox was flushed', function () {
    expect($this->app->make(ConsoleKernel::class)->call('env'))->toBe(0);
});
