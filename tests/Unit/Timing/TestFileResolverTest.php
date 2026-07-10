<?php

declare(strict_types=1);

use RawPHP\Warp\Support\Paths;
use RawPHP\Warp\Timing\TestFileResolver;

/** Mimics a Pest-generated test class, which carries its real source path in a static. */
final class WarpResolverPestFixture
{
    public static string $__filename = '';
}

final class WarpResolverPrivateFilenameFixture
{
    private string $__filename;
}

final class WarpResolverUninitializedStaticFilenameFixture
{
    public static string $__filename;
}

final class WarpResolverFilenameSpy
{
    public static int $casts = 0;

    public function __construct(private readonly string $path) {}

    public function __toString(): string
    {
        self::$casts++;

        return $this->path;
    }
}

final class WarpResolverMemoizedPestFixture
{
    public static Stringable $__filename;
}

final class WarpResolverRootAwareFixture {}

final class WarpResolverSameRootMemoFixture {}

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

it('uses the concrete class file for inherited classic phpunit test methods', function () {
    $suffix = bin2hex(random_bytes(4));
    $baseClass = 'WarpResolverInheritedBase'.$suffix;
    $concreteClass = 'WarpResolverConcreteTest'.$suffix;
    $baseFile = $this->root.'/tests/Unit/'.$baseClass.'.php';
    $concreteFile = $this->root.'/tests/Unit/'.$concreteClass.'.php';

    file_put_contents($baseFile, sprintf(<<<'PHP'
<?php

abstract class %s extends PHPUnit\Framework\TestCase
{
    public function testInheritedTiming(): void
    {
        self::assertTrue(true);
    }
}
PHP,
        $baseClass,
    ));
    file_put_contents($concreteFile, sprintf(<<<'PHP'
<?php

require_once %s;

final class %s extends %s {}
PHP,
        var_export($baseFile, true),
        $concreteClass,
        $baseClass,
    ));
    require_once $concreteFile;

    expect(TestFileResolver::resolve($concreteClass, $baseFile, $this->root))
        ->toBe('tests/Unit/'.$concreteClass.'.php');
});

it('prefers the pest-generated filename over the eval\'d report', function () {
    $reported = "/proj/vendor/pestphp/pest/src/Factories/TestCaseFactory.php(175) : eval()'d code";

    expect(TestFileResolver::resolve(WarpResolverPestFixture::class, $reported, $this->root))
        ->toBe('tests/Feature/ExampleTest.php');
});

it('falls back to the reported file for hostile userland filename properties', function () {
    expect(TestFileResolver::resolve(WarpResolverPrivateFilenameFixture::class, $this->root.'/tests/Unit/ClassicTest.php', $this->root))
        ->toBe('tests/Unit/ClassicTest.php')
        ->and(TestFileResolver::resolve(WarpResolverUninitializedStaticFilenameFixture::class, $this->root.'/tests/ATest.php', $this->root))
        ->toBe('tests/ATest.php');
});

it('memoizes pest filename resolution per class', function () {
    $reported = "/proj/vendor/pestphp/pest/src/Factories/TestCaseFactory.php(175) : eval()'d code";
    WarpResolverFilenameSpy::$casts = 0;
    WarpResolverMemoizedPestFixture::$__filename = new WarpResolverFilenameSpy($this->root.'/tests/Feature/ExampleTest.php');

    expect(TestFileResolver::resolve(WarpResolverMemoizedPestFixture::class, $reported, $this->root))->toBe('tests/Feature/ExampleTest.php')
        ->and(TestFileResolver::resolve(WarpResolverMemoizedPestFixture::class, $reported, $this->root))->toBe('tests/Feature/ExampleTest.php')
        ->and(TestFileResolver::resolve(WarpResolverMemoizedPestFixture::class, $reported, $this->root))->toBe('tests/Feature/ExampleTest.php')
        ->and(WarpResolverFilenameSpy::$casts)->toBe(1);
});

it('memoizes resolved files per class and root pair', function () {
    $otherRoot = sys_get_temp_dir().'/warp-resolver-other-'.bin2hex(random_bytes(4));
    mkdir($otherRoot.'/specs', 0777, true);
    file_put_contents($otherRoot.'/specs/OtherTest.php', '<?php');

    try {
        expect(TestFileResolver::resolve(WarpResolverRootAwareFixture::class, $this->root.'/tests/ATest.php', $this->root))
            ->toBe('tests/ATest.php')
            ->and(TestFileResolver::resolve(WarpResolverRootAwareFixture::class, $otherRoot.'/specs/OtherTest.php', $otherRoot))
            ->toBe('specs/OtherTest.php');
    } finally {
        unlink($otherRoot.'/specs/OtherTest.php');
        rmdir($otherRoot.'/specs');
        rmdir($otherRoot);
    }
});

it('reuses resolved file cache entries for the same class and root pair', function () {
    $file = $this->root.'/tests/MemoizedTest.php';
    file_put_contents($file, '<?php');

    expect(TestFileResolver::resolve(WarpResolverSameRootMemoFixture::class, $file, $this->root))
        ->toBe('tests/MemoizedTest.php');

    unlink($file);

    expect(TestFileResolver::resolve(WarpResolverSameRootMemoFixture::class, $file, $this->root))
        ->toBe('tests/MemoizedTest.php');
});

it('returns null for eval\'d code without a pest filename', function () {
    expect(TestFileResolver::resolve(stdClass::class, "/x/y.php(1) : eval()'d code", '/proj'))->toBeNull();
});

it('returns null for files outside the project root', function () {
    expect(TestFileResolver::resolve(stdClass::class, '/elsewhere/tests/FooTest.php', '/proj'))->toBeNull();
});

it('uses stable absolute realpaths for existing files outside the project root', function () {
    $externalRoot = sys_get_temp_dir().'/warp-resolver-external-'.bin2hex(random_bytes(4));
    mkdir($externalRoot.'/tests', 0777, true);
    $externalFile = $externalRoot.'/tests/ExternalTest.php';
    file_put_contents($externalFile, '<?php');

    try {
        expect(TestFileResolver::resolve(stdClass::class, $externalFile, $this->root))
            ->toBe((string) realpath($externalFile));
    } finally {
        unlink($externalFile);
        rmdir($externalRoot.'/tests');
        rmdir($externalRoot);
    }
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

it('produces the same keys as shard-time path canonicalization', function (string $input, string $root) {
    chdir($this->root);

    $path = str_replace('{root}', $this->root, $input);
    $canonicalRoot = str_replace('{root}', $this->root, $root);

    expect(TestFileResolver::resolve(stdClass::class, $path, $canonicalRoot))
        ->toBe(Paths::canonical($path, $canonicalRoot));
})->with([
    'absolute nested path' => ['{root}/tests/Feature/ExampleTest.php', '{root}'],
    'root-relative path' => ['tests/ATest.php', '{root}'],
    'dot-slash prefixed path' => ['./tests/ATest.php', '{root}'],
    'nested unit path with trailing root slash' => ['{root}/tests/Unit/ClassicTest.php', '{root}/'],
    'at project root' => ['{root}', '{root}'],
]);

it('has no cacheableClass duplicate and at most three static caches (finding 18)', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Timing/TestFileResolver.php');

    expect($source)
        ->not->toContain('cacheableClass')
        ->not->toContain('cacheableByClass');

    // resolvedByClass, filenameByClass, fileByClass - the fourth parallel cache
    // (cacheableByClass) is gone; cacheable === fileForClass() !== null.
    expect(substr_count($source, 'private static array $'))->toBeLessThanOrEqual(3);
});

it('delegates canonicalization to the shared Paths helper', function () {
    $source = file_get_contents(dirname(__DIR__, 3).'/src/Timing/TestFileResolver.php');

    expect($source)
        ->toContain('use RawPHP\Warp\Support\Paths;')
        ->toContain('Paths::canonical($path, $root, allowOutside: true)')
        ->not->toContain('realpath($path)')
        ->not->toContain('str_replace(\'\\\\\', \'/\', $realPath)');
});
