<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use Illuminate\Foundation\Application;
use RawPHP\Warp\Support\Dirs;
use RuntimeException;

final class SnapshotDatabaseManager
{
    private static ?self $instance = null;

    private function __construct(
        private readonly SnapshotConfig $config,
        private readonly MysqlBinaries $binaries,
        private readonly SnapshotStore $store,
        private readonly CopyOnWriteCloner $cloner,
        private readonly string $key,
        private readonly string $workerDir,
        private MysqldServer $server,
    ) {}

    /** Provision once per process, then point $app's connection at the clone. */
    public static function apply(Application $app): void
    {
        self::$instance ??= self::boot($app);
        self::$instance->applyConnectionConfig($app);
    }

    public static function provisioned(): bool
    {
        return self::$instance !== null;
    }

    /** Fresh committed state: throw the clone away and re-clone from golden (sub-second). */
    public static function recycle(Application $app): void
    {
        $self = self::$instance;

        if ($self === null) {
            return;
        }

        $app->make('db')->purge($self->config->connection);

        try {
            $self->server->stop();
            Dirs::delete($self->workerDir.'/datadir');
            $self->cloner->clone($self->store->datadir($self->key), $self->workerDir.'/datadir');

            $self->server = new MysqldServer(
                $self->binaries,
                $self->workerDir.'/datadir',
                $self->workerDir.'/mysql.sock',
                $self->workerDir.'/error.log',
            );
            $self->server->start();
        } catch (\Throwable $e) {
            // A broken instance must never be reused: the next apply() re-boots fresh.
            self::$instance = null;

            throw $e;
        }

        $self->applyConnectionConfig($app);
    }

    public static function shutdown(): void
    {
        if (self::$instance === null) {
            return;
        }

        try {
            self::$instance->server->stop();
        } finally {
            Dirs::delete(self::$instance->workerDir);
            self::$instance = null;
        }
    }

    private static function boot(Application $app): self
    {
        $config = SnapshotConfig::fromApplication($app);
        $binaries = MysqlBinaries::discover($config->mysqldBinary);
        $store = new SnapshotStore($config->snapshotDir);
        $cloner = new CopyOnWriteCloner;

        $key = SnapshotKey::compute($config->hashPaths, $binaries->version(), $config->database, $config->buildCommand);

        if (! $store->exists($key)) {
            (new GoldenSnapshotBuilder($binaries, $store))->build(
                $key,
                $config->database,
                static fn (string $socket, string $database) => self::runBuildCommand($config, $socket, $database),
            );
        }

        touch($store->path($key)); // LRU marker for prune()
        $store->prune(keep: 3);
        DeadWorkerSweep::run($config->runtimeDir);

        $workerDir = $config->runtimeDir.'/w'.getmypid().'-'.bin2hex(random_bytes(3));
        Dirs::ensure($workerDir);
        file_put_contents($workerDir.'/owner.pid', (string) getmypid());

        $cloner->clone($store->datadir($key), $workerDir.'/datadir');

        $server = new MysqldServer($binaries, $workerDir.'/datadir', $workerDir.'/mysql.sock', $workerDir.'/error.log');
        $server->start();

        register_shutdown_function(static function (): void {
            self::shutdown();
        });

        return new self($config, $binaries, $store, $cloner, $key, $workerDir, $server);
    }

    /** Point the app's connection at our throwaway mysqld; per-test transactions unchanged. */
    private function applyConnectionConfig(Application $app): void
    {
        $connection = $this->config->connection;
        $repository = $app->make('config');

        $repository->set("database.connections.{$connection}", array_merge(
            (array) $repository->get("database.connections.{$connection}"),
            [
                'host' => 'localhost',
                'unix_socket' => $this->server->socket(),
                'database' => $this->config->database,
                'username' => 'root',
                'password' => '',
            ],
        ));
    }

    /** Run the app's migrate/seed command against the build mysqld via env-injected DB_* vars. */
    private static function runBuildCommand(SnapshotConfig $config, string $socket, string $database): void
    {
        $log = sys_get_temp_dir().'/warp-build-'.getmypid().'.log';

        $env = array_merge(getenv(), [
            'DB_CONNECTION' => $config->connection,
            'DB_SOCKET' => $socket,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
        ], $config->buildEnv);

        $process = proc_open($config->buildCommand, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'w'],
            2 => ['file', $log, 'a'],
        ], $pipes, $config->appBasePath, $env);

        if ($process === false) {
            throw new RuntimeException('[warp] failed to spawn the snapshot build command');
        }

        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new RuntimeException(
                "[warp] snapshot build command exited {$exit}:\n".substr((string) file_get_contents($log), -2000),
            );
        }
    }
}
