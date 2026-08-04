<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use RuntimeException;

/**
 * Run the host app's migrate/seed command against a throwaway build mysqld
 * by injecting DB_* env vars. No snapshot lock or datadir I/O — those stay on
 * {@see GoldenSnapshotBuilder} / {@see SnapshotDatabaseManager}.
 *
 * @internal Package snapshot-DB plumbing; not host-facing.
 */
final class SnapshotBuildRunner
{
    /**
     * Spawn $config->buildCommand in $config->appBasePath with connection
     * env pointed at $socket / $database. Throws on spawn or non-zero exit.
     */
    public static function run(SnapshotConfig $config, string $socket, string $database): void
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
