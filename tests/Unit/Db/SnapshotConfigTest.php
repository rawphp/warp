<?php

declare(strict_types=1);

use RawPHP\Warp\Db\SnapshotConfig;

beforeEach(function () {
    config()->set('database.connections.mysql', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'app_test',
        'username' => 'app',
        'password' => 'secret',
    ]);
    config()->set('database.default', 'mysql');
});

afterEach(function () {
    putenv('WARP_DB_SNAPSHOT_DIR');
    putenv('WARP_DB_RUNTIME_DIR');
    putenv('WARP_DB_MYSQLD');
});

it('resolves defaults from the app default connection', function () {
    $config = SnapshotConfig::fromApplication($this->app);

    expect($config->connection)->toBe('mysql')
        ->and($config->database)->toBe('app_test')
        ->and($config->hashPaths)->toBe([
            $this->app->basePath('database/migrations'),
            $this->app->basePath('database/seeders'),
        ])
        ->and($config->buildCommand)->toBe([PHP_BINARY, 'artisan', 'migrate', '--force'])
        ->and($config->buildEnv)->toBe([])
        ->and($config->snapshotDir)->toBe($this->app->basePath('.warp/snapshots'))
        ->and($config->runtimeDir)->toBe('/tmp/warp-db')
        ->and($config->appBasePath)->toBe($this->app->basePath())
        ->and($config->mysqldBinary)->toBeNull();
});

it('honours warp.db config overrides', function () {
    config()->set('warp.db', [
        'connection' => 'mysql',
        'database' => 'custom_db',
        'hash_paths' => ['/x/migrations'],
        'build_command' => ['php', 'artisan', 'migrate', '--seed', '--force'],
        'build_env' => ['APP_ENV' => 'testing'],
        'snapshot_dir' => '/x/snapshots',
        'runtime_dir' => '/tmp/x',
        'mysqld' => '/x/bin/mysqld',
    ]);

    $config = SnapshotConfig::fromApplication($this->app);

    expect($config->database)->toBe('custom_db')
        ->and($config->hashPaths)->toBe(['/x/migrations'])
        ->and($config->buildCommand)->toBe(['php', 'artisan', 'migrate', '--seed', '--force'])
        ->and($config->buildEnv)->toBe(['APP_ENV' => 'testing'])
        ->and($config->snapshotDir)->toBe('/x/snapshots')
        ->and($config->runtimeDir)->toBe('/tmp/x')
        ->and($config->mysqldBinary)->toBe('/x/bin/mysqld');
});

it('lets env vars override dirs and binary', function () {
    putenv('WARP_DB_SNAPSHOT_DIR=/env/snapshots');
    putenv('WARP_DB_RUNTIME_DIR=/tmp/env');
    putenv('WARP_DB_MYSQLD=/env/mysqld');

    $config = SnapshotConfig::fromApplication($this->app);

    expect($config->snapshotDir)->toBe('/env/snapshots')
        ->and($config->runtimeDir)->toBe('/tmp/env')
        ->and($config->mysqldBinary)->toBe('/env/mysqld');
});

it('rejects a non-mysql connection', function () {
    config()->set('database.default', 'testing'); // testbench's sqlite connection

    SnapshotConfig::fromApplication($this->app);
})->throws(RuntimeException::class, '[warp] WARP_DB needs a mysql connection');

it('rejects an empty database name', function () {
    config()->set('database.connections.mysql.database', '');

    SnapshotConfig::fromApplication($this->app);
})->throws(RuntimeException::class, '[warp] no database name');
