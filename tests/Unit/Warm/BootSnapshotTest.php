<?php

declare(strict_types=1);

use RawPHP\Warp\Warm\BootSnapshot;

it('keeps the multi-bag constructor non-public so only capture() can build a coherent restore set', function () {
    $constructor = (new ReflectionClass(BootSnapshot::class))->getConstructor();

    expect($constructor)->toBeInstanceOf(ReflectionMethod::class)
        ->and($constructor->isPublic())->toBeFalse()
        ->and($constructor->isPrivate() || $constructor->isProtected())->toBeTrue();
});
