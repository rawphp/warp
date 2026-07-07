<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Db\MysqlBinaries;
use RawPHP\Warp\Db\MysqldServer;

beforeEach(function () {
    if (! mysqldAvailable()) {
        $this->markTestSkipped('mysqld not found — install MySQL 8 or set WARP_DB_MYSQLD');
    }

    $this->tmp = sys_get_temp_dir().'/warp-srv-'.bin2hex(random_bytes(4));
    // /tmp keeps the socket under macOS's 104-byte sun_path cap.
    $this->socket = '/tmp/warp-t'.getmypid().'-'.bin2hex(random_bytes(3)).'.sock';
    $this->server = new MysqldServer(
        MysqlBinaries::discover(),
        $this->tmp.'/datadir',
        $this->socket,
        $this->tmp.'/error.log',
    );
});

afterEach(function () {
    if (isset($this->server)) {
        try {
            $this->server->stop();
        } catch (Throwable) {
        }
    }
    if (isset($this->tmp)) {
        Dirs::delete($this->tmp);
    }
    if (isset($this->socket)) {
        @unlink($this->socket);
    }
});

it('rejects socket paths longer than 100 chars at construction', function () {
    new MysqldServer(MysqlBinaries::discover(), '/tmp/d', '/tmp/'.str_repeat('x', 120).'.sock', '/tmp/e.log');
})->throws(RuntimeException::class, '[warp] socket path too long');

it('initializes, starts, serves a database, and shuts down cleanly enough to restart', function () {
    $this->server->initialize();
    $this->server->start();

    expect($this->server->running())->toBeTrue()
        ->and(file_exists($this->socket))->toBeTrue();

    $this->server->createDatabase('warp_it');

    // Clean shutdown is the golden-snapshot contract: a restart from the same
    // datadir must come up without crash recovery.
    $this->server->stop();

    expect($this->server->running())->toBeFalse();

    $this->server->start();

    expect($this->server->running())->toBeTrue()
        ->and(file_get_contents($this->tmp.'/error.log'))->not->toContain('Database was not shut down normally');
});

it('stop is a no-op when never started', function () {
    $this->server->stop();

    expect($this->server->running())->toBeFalse();
});
