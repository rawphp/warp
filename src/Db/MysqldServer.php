<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use RuntimeException;

final class MysqldServer
{
    /** @var resource|null */
    private $process = null;

    public function __construct(
        private readonly MysqlBinaries $binaries,
        private readonly string $datadir,
        private readonly string $socket,
        private readonly string $errorLog,
    ) {
        // macOS caps sun_path at 104 bytes; fail loudly here, not deep inside mysqld.
        if (strlen($this->socket) > 100) {
            throw new RuntimeException(
                '[warp] socket path too long ('.strlen($this->socket).' > 100 chars): '.$this->socket,
            );
        }
    }

    public function socket(): string
    {
        return $this->socket;
    }

    /** Create a virgin datadir with root@localhost and no password. */
    public function initialize(int $timeoutSeconds = 120): void
    {
        Dirs::ensure(dirname($this->datadir));

        self::runToCompletion([
            $this->binaries->mysqld,
            '--no-defaults',
            '--initialize-insecure',
            '--datadir='.$this->datadir,
            '--log-error='.$this->errorLog,
        ], $timeoutSeconds, $this->errorLog);
    }

    public function start(int $timeoutSeconds = 60): void
    {
        $this->process = proc_open($this->flags(), [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->errorLog, 'a'],
            2 => ['file', $this->errorLog, 'a'],
        ], $pipes);

        if ($this->process === false) {
            throw new RuntimeException('[warp] failed to spawn mysqld');
        }

        $this->waitUntilReady($timeoutSeconds);
    }

    public function running(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    public function createDatabase(string $name): void
    {
        self::runToCompletion([
            $this->binaries->mysqladmin,
            '--no-defaults',
            '--socket='.$this->socket,
            '--user=root',
            'create',
            $name,
        ], 30, $this->errorLog);
    }

    /** SIGTERM = clean InnoDB shutdown; golden clones must never need crash recovery. */
    public function stop(int $timeoutSeconds = 60): void
    {
        if (! is_resource($this->process)) {
            return;
        }

        proc_terminate($this->process, 15);

        $deadline = microtime(true) + $timeoutSeconds;

        while (proc_get_status($this->process)['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($this->process, 9);
                proc_close($this->process);
                $this->process = null;

                throw new RuntimeException('[warp] mysqld refused to shut down cleanly — see '.$this->errorLog);
            }

            usleep(50_000);
        }

        proc_close($this->process);
        $this->process = null;
    }

    /** @return list<string> */
    private function flags(): array
    {
        return [
            $this->binaries->mysqld,
            '--no-defaults',
            '--datadir='.$this->datadir,
            '--socket='.$this->socket,
            '--pid-file='.$this->datadir.'/warp-mysqld.pid',
            '--log-error='.$this->errorLog,
            '--skip-networking',                    // unix socket only — no port juggling
            '--mysqlx=OFF',                         // no second (X plugin) socket
            '--skip-log-bin',                       // throwaway data: durability off
            '--sync_binlog=0',
            '--innodb_flush_log_at_trx_commit=0',
            '--innodb_doublewrite=OFF',
        ];
    }

    private function waitUntilReady(int $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (! $this->running()) {
                $this->process = null;

                throw new RuntimeException('[warp] mysqld exited during startup — see '.$this->errorLog);
            }

            // mysqld creates the socket only once it accepts connections.
            $probe = @stream_socket_client('unix://'.$this->socket, $errno, $error, 1);

            if ($probe !== false) {
                fclose($probe);

                return;
            }

            usleep(50_000);
        }

        $this->stop();

        throw new RuntimeException('[warp] mysqld not ready within '.$timeoutSeconds.'s — see '.$this->errorLog);
    }

    /** @param list<string> $command */
    private static function runToCompletion(array $command, int $timeoutSeconds, string $errorLog): void
    {
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $errorLog, 'a'],
            2 => ['file', $errorLog, 'a'],
        ], $pipes);

        if ($process === false) {
            throw new RuntimeException('[warp] failed to run '.$command[0]);
        }

        $deadline = microtime(true) + $timeoutSeconds;

        while (proc_get_status($process)['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                proc_close($process);

                throw new RuntimeException('[warp] timed out: '.implode(' ', $command).' — see '.$errorLog);
            }

            usleep(50_000);
        }

        $exit = proc_get_status($process)['exitcode'];
        proc_close($process);

        if ($exit !== 0) {
            throw new RuntimeException('[warp] `'.implode(' ', $command).'` exited '.$exit.' — see '.$errorLog);
        }
    }
}
