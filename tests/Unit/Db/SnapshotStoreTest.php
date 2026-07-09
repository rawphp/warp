<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Db\SnapshotStore;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/warp-store-'.bin2hex(random_bytes(4));
    $this->store = new SnapshotStore($this->root);
    $this->key = str_repeat('ab', 20); // 40-char fake sha1
});

afterEach(function () {
    Dirs::delete($this->root);
});

it('addresses snapshots by the first 16 chars of the key', function () {
    expect($this->store->path($this->key))->toBe($this->root.'/'.substr($this->key, 0, 16))
        ->and($this->store->datadir($this->key))->toBe($this->root.'/'.substr($this->key, 0, 16).'/datadir');
});

it('exists only when the datadir is present', function () {
    expect($this->store->exists($this->key))->toBeFalse();

    Dirs::ensure($this->store->datadir($this->key));

    expect($this->store->exists($this->key))->toBeTrue();
});

it('runs the locked callback and returns its value', function () {
    $result = $this->store->withLock($this->key, fn (): string => 'built');

    expect($result)->toBe('built')
        ->and(file_exists($this->store->path($this->key).'.lock'))->toBeTrue();
});

it('releases the lock when the callback throws', function () {
    try {
        $this->store->withLock($this->key, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    // Re-acquiring immediately proves the lock was released.
    expect($this->store->withLock($this->key, fn (): bool => true))->toBeTrue();
});

it('promotes a staging dir atomically into the keyed slot', function () {
    $staging = $this->store->stagingPath($this->key);
    Dirs::ensure($staging.'/datadir');
    file_put_contents($staging.'/datadir/ibdata1', 'x');

    $this->store->promote($staging, $this->key);

    expect($this->store->exists($this->key))->toBeTrue()
        ->and(file_exists($staging))->toBeFalse();
});

it('throws a catchable runtime exception when promotion fails', function () {
    $staging = $this->store->stagingPath($this->key);

    set_error_handler(static fn (): bool => true);

    try {
        expect(fn () => $this->store->promote($staging, $this->key))
            ->toThrow(RuntimeException::class, '[warp] failed to promote snapshot '.$staging);
    } finally {
        restore_error_handler();
    }
});

it('prunes all but the most recently used snapshots, skipping staging dirs', function () {
    foreach (['1111111111111111', '2222222222222222', '3333333333333333'] as $i => $name) {
        Dirs::ensure($this->root.'/'.$name.'/datadir');
        touch($this->root.'/'.$name, time() - 1000 + $i);
    }
    Dirs::ensure($this->root.'/1111111111111111.staging-999');

    $this->store->prune(keep: 2);

    expect(is_dir($this->root.'/1111111111111111'))->toBeFalse()
        ->and(is_dir($this->root.'/2222222222222222'))->toBeTrue()
        ->and(is_dir($this->root.'/3333333333333333'))->toBeTrue()
        ->and(is_dir($this->root.'/1111111111111111.staging-999'))->toBeTrue();
});
