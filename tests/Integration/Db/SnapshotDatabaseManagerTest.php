<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use RawPHP\Warp\Db\MysqldServer;
use RawPHP\Warp\Db\SnapshotDatabaseManager;
use RawPHP\Warp\Support\Dirs;
use RawPHP\Warp\Support\ProcessProbe;

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
    Dirs::installTestBeforeFsOp(null);
    if (isset($this->goldenBackup) && is_dir($this->goldenBackup)) {
        rename($this->goldenBackup, $this->golden);
    }
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

it('resets the singleton and rethrows when server->stop() throws mid-recycle, so the next apply re-provisions', function () {
    SnapshotDatabaseManager::apply($this->app);

    // Force MysqldServer::stop() to throw by swapping its process handle for a
    // resource of the wrong subtype — proc_terminate() rejects it with a TypeError,
    // simulating a genuine stop() failure without waiting out a real mysqld timeout.
    $instance = (new ReflectionProperty(SnapshotDatabaseManager::class, 'instance'))->getValue();
    $worker = (new ReflectionProperty(SnapshotDatabaseManager::class, 'worker'))->getValue($instance);
    $workerDir = $worker->workerDir();
    $server = $worker->server();
    $orphanPid = (int) @file_get_contents($workerDir.'/datadir/warp-mysqld.pid');
    $processProperty = new ReflectionProperty(MysqldServer::class, 'process');
    $wrongResource = fopen('php://memory', 'r');
    $processProperty->setValue($server, $wrongResource);

    $thrown = null;

    try {
        SnapshotDatabaseManager::recycle($this->app);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown)->toBeInstanceOf(TypeError::class)
        ->and(SnapshotDatabaseManager::provisioned())->toBeFalse();

    fclose($wrongResource);

    // recycle() lost the real process handle before it could stop the still-running
    // mysqld — reap it directly (pid read before recycle; cleanup removes the pid
    // file with the worker dir) so this test doesn't leak the process.
    if ($orphanPid > 0) {
        exec(sprintf('kill -9 %d 2>/dev/null', $orphanPid));
    }
    Dirs::delete($workerDir);

    // A subsequent apply() must re-provision a fresh instance, not reuse the dead one.
    DB::purge('warp_it');
    SnapshotDatabaseManager::apply($this->app);

    expect(SnapshotDatabaseManager::provisioned())->toBeTrue()
        ->and(DB::table('marks')->count())->toBe(1);
});

/**
 * Apply, then hide the golden datadir so the next re-clone fails mid-recycle
 * (after a clean stop). Restored in afterEach if a test leaves it hidden.
 *
 * @return array{0: string, 1: int} worker dir and its mysqld pid
 */
function applyThenBreakNextClone(object $test, Application $app): array
{
    SnapshotDatabaseManager::apply($app);

    // Worker dirs are w<pid>-<random>: only ours, not a concurrent run's.
    $workerDir = glob('/tmp/warp-mgr-it/w'.getmypid().'-*')[0];
    $pid = (int) file_get_contents($workerDir.'/datadir/warp-mysqld.pid');

    $test->golden = glob($test->tmp.'/snapshots/*/datadir')[0];
    $test->goldenBackup = $test->golden.'.hidden';
    rename($test->golden, $test->goldenBackup);

    return [$workerDir, $pid];
}

it('a failing recycle stops mysqld, removes the worker dir, and the next apply provisions exactly one', function () {
    [$workerDir, $pid] = applyThenBreakNextClone($this, $this->app);
    DB::purge('warp_it');

    $thrown = null;

    try {
        SnapshotDatabaseManager::recycle($this->app);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toContain('clone source missing')
        ->and(SnapshotDatabaseManager::provisioned())->toBeFalse()
        ->and(is_dir($workerDir))->toBeFalse()
        ->and(ProcessProbe::alive($pid))->toBeFalse();

    rename($this->goldenBackup, $this->golden);
    SnapshotDatabaseManager::apply($this->app);

    expect(glob('/tmp/warp-mgr-it/w'.getmypid().'-*'))->toHaveCount(1)
        ->and(DB::table('marks')->count())->toBe(1);
});

it('a failing recycle rethrows the original exception when the worker cleanup fails too', function () {
    [$workerDir] = applyThenBreakNextClone($this, $this->app);
    DB::purge('warp_it');

    Dirs::installTestBeforeFsOp(static function (string $op, string $path) use ($workerDir): void {
        if ($op === 'rmdir' && $path === $workerDir) {
            throw new RuntimeException('cleanup exploded');
        }
    });

    $thrown = null;

    try {
        SnapshotDatabaseManager::recycle($this->app);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toContain('clone source missing')
        ->and(SnapshotDatabaseManager::provisioned())->toBeFalse();
});
