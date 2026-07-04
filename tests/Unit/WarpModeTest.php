<?php

declare(strict_types=1);

use Warp\WarpMode;

it('is disabled when WARP_WARM is unset', function () {
    putenv('WARP_WARM');

    expect(WarpMode::enabled())->toBeFalse();
});

it('is enabled only when WARP_WARM=1', function () {
    putenv('WARP_WARM=1');
    expect(WarpMode::enabled())->toBeTrue();

    putenv('WARP_WARM=0');
    expect(WarpMode::enabled())->toBeFalse();

    putenv('WARP_WARM');
});
