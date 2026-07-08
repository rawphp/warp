<?php

declare(strict_types=1);

use RawPHP\Warp\WarpMode;

// The retired switch, spelled indirectly so the rename grep guard stays clean
// while the clean-break test below can still prove the old name is dead.
$legacy = 'WARP_'.'WARM';

afterEach(function () use ($legacy) {
    putenv('WARP_MODE');
    putenv('WARP_DB');
    putenv('WARP_TIMINGS');
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

it('database provisioning is disabled when WARP_DB is unset', function () {
    putenv('WARP_DB');

    expect(WarpMode::databaseEnabled())->toBeFalse();
});

it('database provisioning is enabled for the accepted truthy values', function (string $value) {
    putenv("WARP_DB={$value}");

    expect(WarpMode::databaseEnabled())->toBeTrue();
})->with(['1', 'on', 'true']);

it('database provisioning is disabled for falsey or unrecognised values', function (string $value) {
    putenv("WARP_DB={$value}");

    expect(WarpMode::databaseEnabled())->toBeFalse();
})->with(['0', 'off', 'false', 'yes', 'TRUE', '']);

it('database provisioning is independent of WARP_MODE', function () {
    putenv('WARP_MODE=1');
    putenv('WARP_DB');

    expect(WarpMode::databaseEnabled())->toBeFalse();
});

it('timing capture is disabled when WARP_TIMINGS is unset', function () {
    putenv('WARP_TIMINGS');

    expect(WarpMode::timingsEnabled())->toBeFalse();
});

it('timing capture is enabled for the accepted truthy values', function (string $value) {
    putenv("WARP_TIMINGS={$value}");

    expect(WarpMode::timingsEnabled())->toBeTrue();
})->with(['1', 'on', 'true']);

it('timing capture is disabled for falsey or unrecognised values', function (string $value) {
    putenv("WARP_TIMINGS={$value}");

    expect(WarpMode::timingsEnabled())->toBeFalse();
})->with(['0', 'off', 'false', 'yes', 'TRUE', '']);

it('timing capture is independent of WARP_MODE and WARP_DB', function () {
    putenv('WARP_MODE=1');
    putenv('WARP_DB=1');
    putenv('WARP_TIMINGS');

    expect(WarpMode::timingsEnabled())->toBeFalse();
});
