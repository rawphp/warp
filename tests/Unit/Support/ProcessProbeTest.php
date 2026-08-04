<?php

declare(strict_types=1);

use RawPHP\Warp\Support\ProcessProbe;

it('reports the current process as alive', function () {
    expect(ProcessProbe::alive(getmypid()))->toBeTrue();
});

it('reports non-positive and dead pids as not alive', function () {
    expect(ProcessProbe::alive(0))->toBeFalse()
        ->and(ProcessProbe::alive(-1))->toBeFalse();

    $dead = (int) trim((string) shell_exec(PHP_BINARY.' -r "echo getmypid();"'));

    expect(ProcessProbe::alive($dead))->toBeFalse();
});

it('signal is a silent no-op for non-positive pids', function () {
    ProcessProbe::signal(0);
    ProcessProbe::signal(-5);

    expect(true)->toBeTrue();
});
