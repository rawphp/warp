<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Shard\TestFileFinder;

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
