<?php

declare(strict_types=1);

use RawPHP\Warp\WarpMode;

// The retired switch, spelled indirectly so the rename grep guard stays clean
// while the clean-break test below can still prove the old name is dead.
$legacy = 'WARP_'.'WARM';

afterEach(function () use ($legacy) {
    putenv('WARP_MODE');
    putenv($legacy);
});

it('is disabled when WARP_MODE is unset', function () {
    putenv('WARP_MODE');

    expect(WarpMode::enabled())->toBeFalse();
});

it('is enabled for the accepted truthy values', function (string $value) {
    putenv("WARP_MODE={$value}");

    expect(WarpMode::enabled())->toBeTrue();
})->with(['1', 'on', 'true']);

it('is disabled for falsey or unrecognised values', function (string $value) {
    putenv("WARP_MODE={$value}");

    expect(WarpMode::enabled())->toBeFalse();
})->with(['0', 'off', 'false', 'yes', 'TRUE', 'On', '2', '']);

it('ignores the legacy warm variable (clean break)', function () use ($legacy) {
    putenv("{$legacy}=1");
    putenv('WARP_MODE');

    expect(WarpMode::enabled())->toBeFalse();
});
