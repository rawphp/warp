<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Db\MysqlBinaries;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/warp-bin-'.bin2hex(random_bytes(4));
    Dirs::ensure($this->tmp);

    file_put_contents($this->tmp.'/mysqld', "#!/bin/sh\necho 'mysqld  Ver 8.4.0 for warp-test'\n");
    file_put_contents($this->tmp.'/mysqladmin', "#!/bin/sh\nexit 0\n");
    chmod($this->tmp.'/mysqld', 0755);
    chmod($this->tmp.'/mysqladmin', 0755);
});

afterEach(function () {
    putenv('WARP_DB_MYSQLD');
    Dirs::delete($this->tmp);
});

it('uses an explicit mysqld path and finds the sibling mysqladmin', function () {
    $binaries = MysqlBinaries::discover($this->tmp.'/mysqld');

    expect($binaries->mysqld)->toBe($this->tmp.'/mysqld')
        ->and($binaries->mysqladmin)->toBe($this->tmp.'/mysqladmin');
});

it('honours the WARP_DB_MYSQLD env override', function () {
    putenv('WARP_DB_MYSQLD='.$this->tmp.'/mysqld');

    expect(MysqlBinaries::discover()->mysqld)->toBe($this->tmp.'/mysqld');
});

it('throws a warp-prefixed error when the explicit path is not executable', function () {
    MysqlBinaries::discover($this->tmp.'/missing');
})->throws(RuntimeException::class, '[warp] mysqld not found');

it('throws when mysqladmin cannot be located next to mysqld or on PATH', function () {
    unlink($this->tmp.'/mysqladmin');
    $originalPath = getenv('PATH');
    putenv('PATH='.$this->tmp);

    try {
        MysqlBinaries::discover($this->tmp.'/mysqld');
    } finally {
        putenv('PATH='.$originalPath);
    }
})->throws(RuntimeException::class, '[warp] mysqladmin not found');

it('reads the version banner from mysqld --version', function () {
    $binaries = MysqlBinaries::discover($this->tmp.'/mysqld');

    expect($binaries->version())->toBe('mysqld  Ver 8.4.0 for warp-test');
});
