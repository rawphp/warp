<?php

declare(strict_types=1);

use RawPHP\Warp\Db\MysqlBinaries;
use RawPHP\Warp\Db\MysqldServer;

function unitMysqldServer(): MysqldServer
{
    return new MysqldServer(
        new MysqlBinaries('/usr/bin/mysqld', '/usr/bin/mysqladmin'),
        '/tmp/warp-unit/datadir',
        '/tmp/warp-unit.sock',
        '/tmp/warp-unit/error.log',
    );
}

it('starts the worker mysqld with native AIO off', function () {
    $flags = (fn () => $this->flags())->call(unitMysqldServer());

    expect($flags)->toContain('--innodb-use-native-aio=OFF');
});

it('initializes the golden datadir with native AIO off', function () {
    $flags = (fn () => $this->initializeFlags())->call(unitMysqldServer());

    expect($flags)
        ->toContain('--initialize-insecure')
        ->toContain('--innodb-use-native-aio=OFF');
});
