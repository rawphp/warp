<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Support\FileLock;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/warp-file-lock-'.bin2hex(random_bytes(4));
    Dirs::ensure($this->root);
    $this->lockFile = $this->root.'/test.lock';
});

afterEach(function () {
    Dirs::delete($this->root);
});

it('returns the locked callback value', function () {
    $result = FileLock::withLock($this->lockFile, fn (): string => 'locked');

    expect($result)->toBe('locked');
});

it('releases the lock when the callback throws', function () {
    try {
        FileLock::withLock($this->lockFile, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    $handle = fopen($this->lockFile, 'c');

    expect($handle)->not->toBeFalse()
        ->and(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();

    flock($handle, LOCK_UN);
    fclose($handle);
});

it('throws a warp-prefixed runtime exception when the lock file cannot be opened', function () {
    FileLock::withLock($this->root.'/missing/test.lock', fn (): bool => true);
})->throws(RuntimeException::class, '[warp] cannot open file lock');
