<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use Illuminate\Foundation\Application;
use RuntimeException;

final class SnapshotConfig
{
    /**
     * @param  list<string>  $hashPaths
     * @param  list<string>  $buildCommand
     * @param  array<string, string>  $buildEnv
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $database,
        public readonly array $hashPaths,
        public readonly array $buildCommand,
        public readonly array $buildEnv,
        public readonly string $snapshotDir,
        public readonly string $runtimeDir,
        public readonly string $appBasePath,
        public readonly ?string $mysqldBinary,
    ) {}

    public static function fromApplication(Application $app): self
    {
        $config = $app->make('config');
        $base = $app->basePath();

        $connection = $config->get('warp.db.connection') ?? $config->get('database.default');
        $driver = $config->get("database.connections.{$connection}.driver");

        if ($driver !== 'mysql') {
            throw new RuntimeException(
                "[warp] WARP_DB needs a mysql connection — '{$connection}' uses driver '{$driver}'. Set warp.db.connection.",
            );
        }

        $database = $config->get('warp.db.database')
            ?? $config->get("database.connections.{$connection}.database");

        if (! is_string($database) || $database === '') {
            throw new RuntimeException("[warp] no database name configured for connection '{$connection}'.");
        }

        return new self(
            connection: $connection,
            database: $database,
            hashPaths: $config->get('warp.db.hash_paths') ?? [
                $base.'/database/migrations',
                $base.'/database/seeders',
            ],
            buildCommand: $config->get('warp.db.build_command') ?? [PHP_BINARY, 'artisan', 'migrate', '--force'],
            buildEnv: $config->get('warp.db.build_env') ?? [],
            snapshotDir: (getenv('WARP_DB_SNAPSHOT_DIR') ?: null)
                ?? $config->get('warp.db.snapshot_dir')
                ?? $base.'/.warp/snapshots',
            runtimeDir: (getenv('WARP_DB_RUNTIME_DIR') ?: null)
                ?? $config->get('warp.db.runtime_dir')
                ?? '/tmp/warp-db',
            appBasePath: $base,
            mysqldBinary: (getenv('WARP_DB_MYSQLD') ?: null) ?? $config->get('warp.db.mysqld'),
        );
    }
}
