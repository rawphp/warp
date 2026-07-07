<?php

declare(strict_types=1);

use RawPHP\Warp\Db\CopyOnWriteCloner;
use RawPHP\Warp\Db\Dirs;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/warp-clone-'.bin2hex(random_bytes(4));
    Dirs::ensure($this->tmp.'/src/nested');
    file_put_contents($this->tmp.'/src/ibdata1', 'root-file');
    file_put_contents($this->tmp.'/src/nested/table.ibd', 'nested-file');
    $this->cloner = new CopyOnWriteCloner;
});

afterEach(function () {
    Dirs::delete($this->tmp);
});

it('clones a directory tree with contents intact', function () {
    $this->cloner->clone($this->tmp.'/src', $this->tmp.'/dst');

    expect(file_get_contents($this->tmp.'/dst/ibdata1'))->toBe('root-file')
        ->and(file_get_contents($this->tmp.'/dst/nested/table.ibd'))->toBe('nested-file');
});

it('produces an independent copy — writes do not leak back to the source', function () {
    $this->cloner->clone($this->tmp.'/src', $this->tmp.'/dst');
    file_put_contents($this->tmp.'/dst/ibdata1', 'mutated');

    expect(file_get_contents($this->tmp.'/src/ibdata1'))->toBe('root-file');
});

it('replaces an existing destination', function () {
    Dirs::ensure($this->tmp.'/dst');
    file_put_contents($this->tmp.'/dst/stale.txt', 'old');

    $this->cloner->clone($this->tmp.'/src', $this->tmp.'/dst');

    expect(file_exists($this->tmp.'/dst/stale.txt'))->toBeFalse()
        ->and(file_exists($this->tmp.'/dst/ibdata1'))->toBeTrue();
});

it('throws on a missing source', function () {
    $this->cloner->clone($this->tmp.'/missing', $this->tmp.'/dst');
})->throws(RuntimeException::class, '[warp] clone source missing');
