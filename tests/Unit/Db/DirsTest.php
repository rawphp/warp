<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/warp-dirs-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    Dirs::delete($this->tmp);
});

it('ensures a nested directory exists', function () {
    Dirs::ensure($this->tmp.'/a/b/c');

    expect(is_dir($this->tmp.'/a/b/c'))->toBeTrue();
});

it('ensure is idempotent', function () {
    Dirs::ensure($this->tmp.'/a');
    Dirs::ensure($this->tmp.'/a');

    expect(is_dir($this->tmp.'/a'))->toBeTrue();
});

it('deletes a nested tree with files', function () {
    Dirs::ensure($this->tmp.'/a/b');
    file_put_contents($this->tmp.'/a/b/file.txt', 'x');
    file_put_contents($this->tmp.'/a/top.txt', 'y');

    Dirs::delete($this->tmp.'/a');

    expect(file_exists($this->tmp.'/a'))->toBeFalse();
});

it('delete is a silent no-op on a missing path', function () {
    Dirs::delete($this->tmp.'/nope');

    expect(true)->toBeTrue();
});

it('delete removes a plain file too', function () {
    Dirs::ensure($this->tmp);
    file_put_contents($this->tmp.'/f.txt', 'x');

    Dirs::delete($this->tmp.'/f.txt');

    expect(file_exists($this->tmp.'/f.txt'))->toBeFalse();
});
