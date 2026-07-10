<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Shard\TestFileFinder;
use SebastianBergmann\FileIterator\Facade;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/warp-finder-'.bin2hex(random_bytes(4));
    Dirs::ensure($this->tmp.'/tests/Unit/deep');
    Dirs::ensure($this->tmp.'/tests/Feature');
    file_put_contents($this->tmp.'/tests/Unit/ATest.php', '<?php');
    file_put_contents($this->tmp.'/tests/Unit/deep/BTest.php', '<?php');
    file_put_contents($this->tmp.'/tests/Unit/Helper.php', '<?php');
    file_put_contents($this->tmp.'/tests/Feature/CTest.php', '<?php');
});

afterEach(function () {
    Dirs::delete($this->tmp);
});

it('finds suffix-matching files recursively, sorted', function () {
    expect(TestFileFinder::find([$this->tmp.'/tests']))->toBe([
        $this->tmp.'/tests/Feature/CTest.php',
        $this->tmp.'/tests/Unit/ATest.php',
        $this->tmp.'/tests/Unit/deep/BTest.php',
    ]);
});

it('accepts explicit files regardless of suffix and dedupes across paths', function () {
    $found = TestFileFinder::find([
        $this->tmp.'/tests/Unit/Helper.php',
        $this->tmp.'/tests/Unit/ATest.php',
        $this->tmp.'/tests/Unit',
    ]);

    expect($found)->toBe([
        $this->tmp.'/tests/Unit/ATest.php',
        $this->tmp.'/tests/Unit/Helper.php',
        $this->tmp.'/tests/Unit/deep/BTest.php',
    ]);
});

it('honours a custom suffix', function () {
    expect(TestFileFinder::find([$this->tmp.'/tests/Unit'], '.php'))->toBe([
        $this->tmp.'/tests/Unit/ATest.php',
        $this->tmp.'/tests/Unit/Helper.php',
        $this->tmp.'/tests/Unit/deep/BTest.php',
    ]);
});

it('rejects an empty suffix', function () {
    TestFileFinder::find([$this->tmp.'/tests/Unit'], '');
})->throws(RuntimeException::class, '[warp] test file suffix must not be empty');

it('strips a trailing slash from directory args', function () {
    expect(TestFileFinder::find([$this->tmp.'/tests/Feature/']))->toBe([
        $this->tmp.'/tests/Feature/CTest.php',
    ]);
});

it('throws on a missing path', function () {
    TestFileFinder::find([$this->tmp.'/nope']);
})->throws(RuntimeException::class, '[warp] no such test path');

it('discovers symlinked directories and .phpt files by default, excluding hidden directories', function () {
    Dirs::ensure($this->tmp.'/shared');
    file_put_contents($this->tmp.'/shared/SharedTest.php', '<?php');
    symlink($this->tmp.'/shared', $this->tmp.'/tests/Linked');

    file_put_contents($this->tmp.'/tests/Feature/example.phpt', "--TEST--\nExample\n--FILE--\n<?php\n--EXPECT--\n");

    Dirs::ensure($this->tmp.'/tests/.cache');
    file_put_contents($this->tmp.'/tests/.cache/StaleTest.php', '<?php');

    expect(TestFileFinder::find([$this->tmp.'/tests']))->toBe([
        $this->tmp.'/tests/Feature/CTest.php',
        $this->tmp.'/tests/Feature/example.phpt',
        $this->tmp.'/tests/Linked/SharedTest.php',
        $this->tmp.'/tests/Unit/ATest.php',
        $this->tmp.'/tests/Unit/deep/BTest.php',
    ]);
});

it('narrows to the explicit suffix, dropping .phpt when --suffix targets Test.php only', function () {
    file_put_contents($this->tmp.'/tests/Feature/example.phpt', "--TEST--\nExample\n--FILE--\n<?php\n--EXPECT--\n");

    expect(TestFileFinder::find([$this->tmp.'/tests'], 'Test.php'))->toBe([
        $this->tmp.'/tests/Feature/CTest.php',
        $this->tmp.'/tests/Unit/ATest.php',
        $this->tmp.'/tests/Unit/deep/BTest.php',
    ]);
});

it('narrows to .phpt exclusively when suffix is explicitly set to .phpt', function () {
    file_put_contents($this->tmp.'/tests/Feature/example.phpt', "--TEST--\nExample\n--FILE--\n<?php\n--EXPECT--\n");

    expect(TestFileFinder::find([$this->tmp.'/tests'], '.phpt'))->toBe([
        $this->tmp.'/tests/Feature/example.phpt',
    ]);
});

it('returns a deterministically sorted result across repeated invocations', function () {
    Dirs::ensure($this->tmp.'/shared');
    file_put_contents($this->tmp.'/shared/SharedTest.php', '<?php');
    symlink($this->tmp.'/shared', $this->tmp.'/tests/Linked');
    file_put_contents($this->tmp.'/tests/Feature/example.phpt', "--TEST--\nExample\n--FILE--\n<?php\n--EXPECT--\n");

    $first = TestFileFinder::find([$this->tmp.'/tests']);
    $second = TestFileFinder::find([$this->tmp.'/tests']);
    $sorted = $first;
    sort($sorted);

    expect($first)->toBe($second)
        ->and($first)->toBe($sorted)
        ->and($first)->toBe(array_values(array_unique($first)));
});

it('matches the phpunit file-iterator facade discovery for the same tree', function () {
    Dirs::ensure($this->tmp.'/shared');
    file_put_contents($this->tmp.'/shared/SharedTest.php', '<?php');
    symlink($this->tmp.'/shared', $this->tmp.'/tests/Linked');
    file_put_contents($this->tmp.'/tests/Feature/example.phpt', "--TEST--\nExample\n--FILE--\n<?php\n--EXPECT--\n");
    Dirs::ensure($this->tmp.'/tests/.cache');
    file_put_contents($this->tmp.'/tests/.cache/StaleTest.php', '<?php');

    $ours = array_values(array_filter(array_map(
        static fn (string $file): string|false => realpath($file),
        TestFileFinder::find([$this->tmp.'/tests']),
    )));
    sort($ours);

    $phpunit = (new Facade)->getFilesAsArray(
        $this->tmp.'/tests',
        ['Test.php', '.phpt'],
    );

    expect($ours)->toBe($phpunit);
});
