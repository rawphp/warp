<?php

declare(strict_types=1);

use Composer\Semver\Semver;

function warpComposerJson(): array
{
    $contents = file_get_contents(dirname(__DIR__, 2).'/composer.json');

    expect($contents)->not->toBeFalse();

    return json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);
}

it('declares PHPUnit as a runtime dependency compatible with Warp internals', function () {
    $composer = warpComposerJson();

    $constraint = $composer['require']['phpunit/phpunit'] ?? null;

    expect($composer['require']['php'])->toBe('^8.4')
        ->and($constraint)->toBeString()
        ->and(Semver::satisfies('10.5.53', $constraint))->toBeFalse()
        ->and(Semver::satisfies('11.0.0', $constraint))->toBeFalse()
        ->and(Semver::satisfies('11.1.0', $constraint))->toBeTrue()
        ->and(Semver::satisfies('12.5.30', $constraint))->toBeTrue()
        ->and($composer['require-dev']['phpunit/phpunit'] ?? null)->toBeNull();
});
