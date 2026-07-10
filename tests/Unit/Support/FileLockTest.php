<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Support\FileLock;

final class FailingLockStream
{
    public static int $closed = 0;

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return $operation !== LOCK_EX;
    }

    public function stream_close(): void
    {
        self::$closed++;
    }
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/warp-file-lock-'.bin2hex(random_bytes(4));
    Dirs::ensure($this->root);
    $this->lockFile = $this->root.'/test.lock';
});

afterEach(function () {
    if (in_array('warp-failing-lock', stream_get_wrappers(), true)) {
        stream_wrapper_unregister('warp-failing-lock');
    }

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

it('throws without invoking the callback when flock fails', function () {
    FailingLockStream::$closed = 0;
    expect(stream_wrapper_register('warp-failing-lock', FailingLockStream::class))->toBeTrue();

    $called = false;

    expect(fn () => FileLock::withLock('warp-failing-lock://test.lock', function () use (&$called): void {
        $called = true;
    }))->toThrow(RuntimeException::class, '[warp] cannot acquire file lock');

    expect($called)->toBeFalse()
        ->and(FailingLockStream::$closed)->toBe(1);
});

it('contains no dead error_get_last fallback (finding 19)', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Support/FileLock.php');

    // The scoped error handler already captures the fopen warning, so the
    // error_get_last() fallback after it was unreachable dead code.
    expect($source)->not->toContain('error_get_last');
});

it('reports the underlying fopen warning when the lock file cannot be opened', function () {
    $called = false;
    $lockFile = $this->root.'/missing/test.lock';
    $thrown = null;

    try {
        FileLock::withLock($lockFile, function () use (&$called): bool {
            $called = true;

            return true;
        });
    } catch (RuntimeException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toContain('[warp] cannot open file lock at '.$lockFile)
        ->and($thrown?->getMessage())->toContain('fopen('.$lockFile.')')
        ->and($thrown?->getMessage())->toContain('No such file or directory')
        ->and($called)->toBeFalse();
});
