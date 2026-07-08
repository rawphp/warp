<?php

declare(strict_types=1);

use RawPHP\Warp\Timing\TestFileResolver;

/** Mimics a Pest-generated test class, which carries its real source path in a static. */
final class WarpResolverPestFixture
{
    public static string $__filename = '/proj/tests/Feature/ExampleTest.php';
}

it('uses the reported file for classic phpunit classes', function () {
    expect(TestFileResolver::resolve(stdClass::class, '/proj/tests/Unit/ClassicTest.php', '/proj'))
        ->toBe('tests/Unit/ClassicTest.php');
});

it('prefers the pest-generated filename over the eval\'d report', function () {
    $reported = "/proj/vendor/pestphp/pest/src/Factories/TestCaseFactory.php(175) : eval()'d code";

    expect(TestFileResolver::resolve(WarpResolverPestFixture::class, $reported, '/proj'))
        ->toBe('tests/Feature/ExampleTest.php');
});

it('returns null for eval\'d code without a pest filename', function () {
    expect(TestFileResolver::resolve(stdClass::class, "/x/y.php(1) : eval()'d code", '/proj'))->toBeNull();
});

it('returns null for files outside the project root', function () {
    expect(TestFileResolver::resolve(stdClass::class, '/elsewhere/tests/FooTest.php', '/proj'))->toBeNull();
});

it('tolerates a trailing slash on the root', function () {
    expect(TestFileResolver::resolve(stdClass::class, '/proj/tests/ATest.php', '/proj/'))
        ->toBe('tests/ATest.php');
});
