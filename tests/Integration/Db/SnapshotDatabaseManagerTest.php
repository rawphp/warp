<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Db\SnapshotDatabaseManager;

beforeEach(function () {
    if (! mysqldAvailable()) {
        $this->markTestSkipped('mysqld not found — install MySQL 8 or set WARP_DB_MYSQLD');
    }
    if (! extension_loaded('pdo_mysql')) {
        $this->markTestSkipped('pdo_mysql not loaded');
    }

    $this->tmp = sys_get_temp_dir().'/warp-mgr-'.bin2hex(random_bytes(4));
    Dirs::ensure($this->tmp.'/migrations');
    file_put_contents($this->tmp.'/migrations/001.sql', 'marks-v1');

    config()->set('database.connections.warp_it', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'warp_it',
        'username' => 'nobody',
        'password' => 'wrong',
    ]);
    config()->set('database.default', 'warp_it');
    config()->set('warp.db', [
        'hash_paths' => [$this->tmp.'/migrations'],
        'snapshot_dir' => $this->tmp.'/snapshots',
        'runtime_dir' => '/tmp/warp-mgr-it',
        // Inline build "migration" — creates schema exactly like `artisan migrate` would.
        'build_command' => [PHP_BINARY, '-r', implode('', [
            '$p = new PDO("mysql:unix_socket=".getenv("DB_SOCKET").";dbname=".getenv("DB_DATABASE"), "root", "");',
            '$p->exec("CREATE TABLE marks (id INT PRIMARY KEY)");',
            '$p->exec("INSERT INTO marks VALUES (1)");',
        ])],
    ]);
});

afterEach(function () {
    SnapshotDatabaseManager::shutdown();
    if (isset($this->tmp)) {
        Dirs::delete($this->tmp);
    }
    Dirs::delete('/tmp/warp-mgr-it');
});

it('provisions a clone and serves Laravel queries through the rewired connection', function () {
    SnapshotDatabaseManager::apply($this->app);

    expect(SnapshotDatabaseManager::provisioned())->toBeTrue()
        ->and(config('database.connections.warp_it.unix_socket'))->toContain('/mysql.sock')
        ->and(config('database.connections.warp_it.username'))->toBe('root')
        ->and(DB::table('marks')->count())->toBe(1);
});

it('reuses the golden snapshot and boots from clone on the second worker-boot', function () {
    SnapshotDatabaseManager::apply($this->app);
    $goldenDirs = glob($this->tmp.'/snapshots/*', GLOB_ONLYDIR);

    SnapshotDatabaseManager::shutdown();
    DB::purge('warp_it');
    SnapshotDatabaseManager::apply($this->app);

    expect(glob($this->tmp.'/snapshots/*', GLOB_ONLYDIR))->toBe($goldenDirs)
        ->and(DB::table('marks')->count())->toBe(1);
});

it('recycle discards committed writes by re-cloning from golden', function () {
    SnapshotDatabaseManager::apply($this->app);

    DB::table('marks')->insert(['id' => 2]);
    expect(DB::table('marks')->count())->toBe(2);

    SnapshotDatabaseManager::recycle($this->app);

    expect(DB::table('marks')->count())->toBe(1);
});

it('apply is idempotent within a process', function () {
    SnapshotDatabaseManager::apply($this->app);
    $socket = config('database.connections.warp_it.unix_socket');

    SnapshotDatabaseManager::apply($this->app);

    expect(config('database.connections.warp_it.unix_socket'))->toBe($socket);
});

it('shutdown removes the worker runtime dir and is idempotent', function () {
    SnapshotDatabaseManager::apply($this->app);

    expect(glob('/tmp/warp-mgr-it/w*'))->not->toBe([]);

    DB::purge('warp_it');
    SnapshotDatabaseManager::shutdown();
    SnapshotDatabaseManager::shutdown();

    expect(glob('/tmp/warp-mgr-it/w*'))->toBe([])
        ->and(SnapshotDatabaseManager::provisioned())->toBeFalse();
});
