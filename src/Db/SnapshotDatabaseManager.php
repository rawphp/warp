<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use Illuminate\Foundation\Application;

/**
 * Process-global WARP_DB provisioning: ensure golden snapshot, hand each
 * process a {@see WorkerRuntime}, rebind the Laravel connection.
 *
 * Host-facing surface: {@see apply()}, {@see recycle()}, {@see shutdown()},
 * {@see provisioned()}. Build-command subprocess lives in
 * {@see SnapshotBuildRunner}; per-worker mysqld lifecycle in {@see WorkerRuntime}.
 */
final class SnapshotDatabaseManager
{
    private static ?self $instance = null;

    private function __construct(
        private readonly SnapshotConfig $config,
        private WorkerRuntime $worker,
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
            $self->worker->recycle();
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
            self::$instance->worker->shutdown();
        } finally {
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
                static fn (string $socket, string $database) => SnapshotBuildRunner::run($config, $socket, $database),
            );
        }

        touch($store->path($key)); // LRU marker for prune()
        $store->prune(keep: 3);
        DeadWorkerSweep::run($config->runtimeDir);

        $worker = WorkerRuntime::provision(
            $config->runtimeDir,
            $binaries,
            $cloner,
            $store->datadir($key),
        );

        register_shutdown_function(static function (): void {
            self::shutdown();
        });

        return new self($config, $worker);
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
                'unix_socket' => $this->worker->socket(),
                'database' => $this->config->database,
                'username' => 'root',
                'password' => '',
            ],
        ));
    }

}
