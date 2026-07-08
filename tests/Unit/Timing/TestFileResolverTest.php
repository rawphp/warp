<?php

declare(strict_types=1);

use RawPHP\Warp\Timing\TestFileResolver;

/** Mimics a Pest-generated test class, which carries its real source path in a static. */
final class WarpResolverPestFixture
{
    public static string $__filename = '';
}

beforeEach(function () {
    $this->cwd = getcwd();
    $this->root = sys_get_temp_dir().'/warp-resolver-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/tests/Feature', 0777, true);
    mkdir($this->root.'/tests/Unit', 0777, true);
    file_put_contents($this->root.'/tests/Feature/ExampleTest.php', '<?php');
    file_put_contents($this->root.'/tests/Unit/ClassicTest.php', '<?php');
    file_put_contents($this->root.'/tests/ATest.php', '<?php');

    WarpResolverPestFixture::$__filename = $this->root.'/tests/Feature/ExampleTest.php';
});

afterEach(function () {
    chdir($this->cwd);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($this->root);
});

it('uses the reported file for classic phpunit classes', function () {
    expect(TestFileResolver::resolve(stdClass::class, $this->root.'/tests/Unit/ClassicTest.php', $this->root))
        ->toBe('tests/Unit/ClassicTest.php');
});

it('prefers the pest-generated filename over the eval\'d report', function () {
    $reported = "/proj/vendor/pestphp/pest/src/Factories/TestCaseFactory.php(175) : eval()'d code";

    expect(TestFileResolver::resolve(WarpResolverPestFixture::class, $reported, $this->root))
        ->toBe('tests/Feature/ExampleTest.php');
});

it('returns null for eval\'d code without a pest filename', function () {
    expect(TestFileResolver::resolve(stdClass::class, "/x/y.php(1) : eval()'d code", '/proj'))->toBeNull();
});

it('returns null for files outside the project root', function () {
    expect(TestFileResolver::resolve(stdClass::class, '/elsewhere/tests/FooTest.php', '/proj'))->toBeNull();
});

it('tolerates a trailing slash on the root', function () {
    expect(TestFileResolver::resolve(stdClass::class, $this->root.'/tests/ATest.php', $this->root.'/'))
        ->toBe('tests/ATest.php');
});

it('emits canonical root-relative keys with no leading dot slash', function () {
    chdir($this->root);

    expect(TestFileResolver::resolve(stdClass::class, './tests/ATest.php', $this->root))
        ->toBe('tests/ATest.php');
});
