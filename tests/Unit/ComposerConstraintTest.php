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

it('keeps the php-file-iterator constraint co-satisfiable with the advertised PHPUnit 11/12 support', function () {
    $composer = warpComposerJson();

    $phpunitConstraint = $composer['require']['phpunit/phpunit'] ?? null;
    $fileIteratorConstraint = $composer['require']['phpunit/php-file-iterator'] ?? null;

    expect($phpunitConstraint)->toBeString()
        ->and($fileIteratorConstraint)->toBeString();

    // PHPUnit 11.x depends on phpunit/php-file-iterator ^5.x; PHPUnit 12.x depends on ^6.x.
    // Whenever the declared phpunit constraint admits a version from one of those major
    // lines, the declared file-iterator constraint must admit a matching version too —
    // otherwise composer install is unsatisfiable for that PHPUnit major.
    if (Semver::satisfies('11.5.0', $phpunitConstraint)) {
        expect(Semver::satisfies('5.0.99', $fileIteratorConstraint))->toBeTrue();
    }

    if (Semver::satisfies('12.5.30', $phpunitConstraint)) {
        expect(Semver::satisfies('6.0.99', $fileIteratorConstraint))->toBeTrue();
    }
});

it('declares composer/semver as a direct require-dev dependency', function () {
    $composer = warpComposerJson();

    expect($composer['require-dev']['composer/semver'] ?? null)->toBeString();
});
