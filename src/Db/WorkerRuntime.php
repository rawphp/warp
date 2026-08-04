<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use RawPHP\Warp\Support\Dirs;

/**
 * Per-worker snapshot clone + throwaway mysqld lifecycle.
 *
 * Owns the worker directory layout (owner.pid, datadir, socket, error log),
 * CoW clone from golden, and start/stop/recycle. Process-global provisioning
 * policy and Laravel connection rebinding stay on {@see SnapshotDatabaseManager}.
 *
 * @internal Package snapshot-DB plumbing; not host-facing.
 */
final class WorkerRuntime
{
    private function __construct(
        private readonly string $workerDir,
        private MysqldServer $server,
        private readonly CopyOnWriteCloner $cloner,
        private readonly MysqlBinaries $binaries,
        private readonly string $goldenDatadir,
    ) {}

    /**
     * Create a unique worker dir under $runtimeDir, clone golden into it,
     * and start a throwaway mysqld on a unix socket.
     */
    public static function provision(
        string $runtimeDir,
        MysqlBinaries $binaries,
        CopyOnWriteCloner $cloner,
        string $goldenDatadir,
    ): self {
        $workerDir = $runtimeDir.'/w'.getmypid().'-'.bin2hex(random_bytes(3));
        Dirs::ensure($workerDir);
        file_put_contents($workerDir.'/owner.pid', (string) getmypid());

        $cloner->clone($goldenDatadir, $workerDir.'/datadir');

        $server = self::newServer($binaries, $workerDir);
        $server->start();

        return new self($workerDir, $server, $cloner, $binaries, $goldenDatadir);
    }

    /**
     * Fresh committed state: stop mysqld, throw the datadir away, re-clone
     * from golden, and start a new server (sub-second on CoW filesystems).
     */
    public function recycle(): void
    {
        $this->server->stop();
        Dirs::delete($this->workerDir.'/datadir');
        $this->cloner->clone($this->goldenDatadir, $this->workerDir.'/datadir');

        $this->server = self::newServer($this->binaries, $this->workerDir);
        $this->server->start();
    }

    /** Stop mysqld and delete the whole worker directory. */
    public function shutdown(): void
    {
        try {
            $this->server->stop();
        } finally {
            Dirs::delete($this->workerDir);
        }
    }

    public function socket(): string
    {
        return $this->server->socket();
    }

    public function workerDir(): string
    {
        return $this->workerDir;
    }

    public function server(): MysqldServer
    {
        return $this->server;
    }

    private static function newServer(MysqlBinaries $binaries, string $workerDir): MysqldServer
    {
        return new MysqldServer(
            $binaries,
            $workerDir.'/datadir',
            $workerDir.'/mysql.sock',
            $workerDir.'/error.log',
        );
    }
}
