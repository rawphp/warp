<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use RuntimeException;
use Throwable;

final class GoldenSnapshotBuilder
{
    public function __construct(
        private readonly MysqlBinaries $binaries,
        private readonly SnapshotStore $store,
    ) {}

    /**
     * Build the golden datadir for $key: initialize, boot, create the database,
     * run $seed against it, shut down CLEANLY, promote atomically.
     *
     * @param  callable(string, string): void  $seed  receives (socket, database)
     */
    public function build(string $key, string $database, callable $seed): void
    {
        $this->store->withLock($key, function () use ($key, $database, $seed): void {
            if ($this->store->exists($key)) {
                return; // another worker built it while we waited on the lock
            }

            $staging = $this->store->stagingPath($key);
            Dirs::delete($staging);
            Dirs::ensure($staging);

            // /tmp keeps the build socket under macOS's 104-byte sun_path cap.
            $socket = '/tmp/warp-gb'.getmypid().'-'.bin2hex(random_bytes(3)).'.sock';

            $server = new MysqldServer(
                $this->binaries,
                $staging.'/datadir',
                $socket,
                $staging.'/build-error.log',
            );

            try {
                $server->initialize();
                $server->start();
                $server->createDatabase($database);
                $seed($socket, $database);
                // Clean shutdown is mandatory: clones must start without crash recovery.
                $server->stop();

                self::verifySchemaLanded($staging, $database);
            } catch (Throwable $e) {
                try {
                    $server->stop();
                } catch (Throwable) {
                }
                Dirs::delete($staging);
                @unlink($socket);

                throw $e;
            }

            @unlink($socket);

            file_put_contents($staging.'/meta.json', json_encode([
                'key' => $key,
                'database' => $database,
                'mysqld_version' => $this->binaries->version(),
                'format' => SnapshotKey::FORMAT,
                'built_at' => date('c'),
            ], JSON_PRETTY_PRINT));

            $this->store->promote($staging, $key);
        });
    }

    /**
     * Trust but verify: the build subprocess can exit 0 while having migrated
     * against the wrong server entirely (e.g. a host/port footgun bypassing the
     * intended build-time socket). Confirm the target schema actually has tables
     * in this datadir before letting the caller promote it as golden.
     */
    private static function verifySchemaLanded(string $staging, string $database): void
    {
        $schemaDir = $staging.'/datadir/'.$database;

        if (! is_dir($schemaDir)) {
            throw new RuntimeException(
                "[warp] golden build produced no schema directory for database '{$database}' — the build subprocess likely migrated against the wrong server",
            );
        }

        $tables = array_filter(
            scandir($schemaDir) ?: [],
            static fn (string $entry): bool => ! in_array($entry, ['.', '..', 'db.opt'], true),
        );

        if ($tables === []) {
            throw new RuntimeException(
                "[warp] golden build database '{$database}' has no tables in the built datadir — the build subprocess likely migrated against the wrong server (check DB_SOCKET/unix_socket wiring)",
            );
        }
    }
}
