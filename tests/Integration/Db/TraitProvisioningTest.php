<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Db\SnapshotDatabaseManager;

// Provisioning happens inside createApplication(), which Pest runs before
// beforeEach — so the WARP_DB env and config seams are set up in the test
// body against a re-created application instead.

afterEach(function () {
    putenv('WARP_DB');
    SnapshotDatabaseManager::shutdown();
    if (isset($this->tmp)) {
        Dirs::delete($this->tmp);
    }
    Dirs::delete('/tmp/warp-trait-it');
});

it('does not provision when WARP_DB is off', function () {
    putenv('WARP_DB');
    $this->refreshApplication();

    expect(SnapshotDatabaseManager::provisioned())->toBeFalse();
});

it('provisions through createApplication when WARP_DB is on, and recycles', function () {
    if (! mysqldAvailable()) {
        $this->markTestSkipped('mysqld not found — install MySQL 8 or set WARP_DB_MYSQLD');
    }
    if (! extension_loaded('pdo_mysql')) {
        $this->markTestSkipped('pdo_mysql not loaded');
    }

    $this->tmp = sys_get_temp_dir().'/warp-trait-'.bin2hex(random_bytes(4));
    Dirs::ensure($this->tmp.'/migrations');
    file_put_contents($this->tmp.'/migrations/001.sql', 'marks-v1');

    // The testbench app re-created below must resolve this config; testbench
    // reads defineEnvironment, so seed it via the config repository once the
    // fresh app exists — provisioning is applied lazily on first use here.
    // WARP_DB is turned on only after the app is configured for mysql: turning
    // it on beforehand would make this refreshApplication() eagerly provision
    // against the still-default sqlite 'testing' connection and throw.
    $this->refreshApplication();

    config()->set('database.connections.warp_trait', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'warp_trait',
        'username' => 'nobody',
        'password' => 'wrong',
    ]);
    config()->set('database.default', 'warp_trait');
    config()->set('warp.db', [
        'hash_paths' => [$this->tmp.'/migrations'],
        'snapshot_dir' => $this->tmp.'/snapshots',
        'runtime_dir' => '/tmp/warp-trait-it',
        'build_command' => [PHP_BINARY, '-r', implode('', [
            '$p = new PDO("mysql:unix_socket=".getenv("DB_SOCKET").";dbname=".getenv("DB_DATABASE"), "root", "");',
            '$p->exec("CREATE TABLE marks (id INT PRIMARY KEY)");',
            '$p->exec("INSERT INTO marks VALUES (1)");',
        ])],
    ]);

    // Simulate the next test's createApplication() on the configured app.
    putenv('WARP_DB=1');
    RawPHP\Warp\Db\SnapshotDatabaseManager::apply($this->app);

    expect(SnapshotDatabaseManager::provisioned())->toBeTrue()
        ->and(DB::table('marks')->count())->toBe(1);

    DB::table('marks')->insert(['id' => 2]);
    $this->warpRecycleDatabase();

    expect(DB::table('marks')->count())->toBe(1);
});
