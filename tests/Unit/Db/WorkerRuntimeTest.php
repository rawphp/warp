<?php

declare(strict_types=1);

use RawPHP\Warp\Db\CopyOnWriteCloner;
use RawPHP\Warp\Db\MysqlBinaries;
use RawPHP\Warp\Db\WorkerRuntime;
use RawPHP\Warp\Support\Dirs;

beforeEach(function () {
    // /tmp keeps the worker socket path under macOS's 104-byte sun_path cap.
    $this->tmp = '/tmp/warp-wrt-'.bin2hex(random_bytes(4));
    $this->runtimeDir = $this->tmp.'/runtime';
    $this->golden = $this->tmp.'/golden';
    Dirs::ensure($this->runtimeDir);
    Dirs::ensure($this->golden);
    file_put_contents($this->golden.'/ibdata1', 'golden');

    // A "mysqld" that dies at once, like one that aborts on io_setup() EAGAIN.
    $this->stub = $this->tmp.'/mysqld';
    file_put_contents($this->stub, "#!/bin/sh\nexit 1\n");
    chmod($this->stub, 0755);
    $this->binaries = new MysqlBinaries($this->stub, $this->stub);
});

afterEach(function () {
    Dirs::installTestBeforeFsOp(null);

    if (isset($this->tmp)) {
        Dirs::delete($this->tmp);
    }
});

it('removes the worker dir and rethrows when mysqld cannot start', function () {
    $thrown = null;

    try {
        WorkerRuntime::provision($this->runtimeDir, $this->binaries, new CopyOnWriteCloner, $this->golden);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toContain('mysqld exited during startup')
        ->and(glob($this->runtimeDir.'/w*'))->toBe([]);
});

it('removes the worker dir and rethrows when the golden clone fails', function () {
    $thrown = null;

    try {
        WorkerRuntime::provision($this->runtimeDir, $this->binaries, new CopyOnWriteCloner, $this->tmp.'/missing');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toContain('clone source missing')
        ->and(glob($this->runtimeDir.'/w*'))->toBe([]);
});

it('rethrows the original start failure when the cleanup itself fails', function () {
    $runtimeDir = $this->runtimeDir;
    Dirs::installTestBeforeFsOp(static function (string $op, string $path) use ($runtimeDir): void {
        if (str_starts_with($path, $runtimeDir.'/w')) {
            throw new RuntimeException('cleanup exploded');
        }
    });

    $thrown = null;

    try {
        WorkerRuntime::provision($this->runtimeDir, $this->binaries, new CopyOnWriteCloner, $this->golden);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toContain('mysqld exited during startup');
});
