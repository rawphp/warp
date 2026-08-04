<?php

declare(strict_types=1);

use RawPHP\Warp\Db\DeadWorkerSweep;
use RawPHP\Warp\Db\SnapshotDatabaseManager;
use RawPHP\Warp\Db\WorkerRuntime;
use RawPHP\Warp\Support\Dirs;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/warp-mgr-teardown-'.bin2hex(random_bytes(4));
    Dirs::ensure($this->tmp);
});

afterEach(function () {
    if (isset($this->stubProcess) && is_resource($this->stubProcess)) {
        // SIGKILL — stub may ignore SIGTERM by design.
        proc_terminate($this->stubProcess, 9);
        proc_close($this->stubProcess);
        $this->stubProcess = null;
    }

    if (isset($this->tmp) && (file_exists($this->tmp) || is_link($this->tmp))) {
        Dirs::delete($this->tmp);
    }
});

/**
 * Spawn a long-lived PHP child that ignores SIGTERM (simulates a stubborn mysqld).
 *
 * @return array{0: resource, 1: int} process handle and pid
 */
function spawnTermIgnoringStub(): array
{
    $process = proc_open(
        [
            PHP_BINARY,
            '-r',
            'pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); sleep(60);',
        ],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ],
        $pipes,
    );

    if ($process === false) {
        throw new RuntimeException('failed to spawn SIGTERM-ignoring stub');
    }

    $status = proc_get_status($process);
    $pid = (int) $status['pid'];

    if ($pid <= 0) {
        proc_terminate($process, 9);
        proc_close($process);

        throw new RuntimeException('stub process has no pid');
    }

    // Brief wait so the child is definitely running and has installed its handler.
    usleep(50_000);

    return [$process, $pid];
}

it('does not define settleAfterStop — FS resilience lives in Dirs delete + backoff', function () {
    expect(method_exists(SnapshotDatabaseManager::class, 'settleAfterStop'))->toBeFalse()
        ->and(method_exists(WorkerRuntime::class, 'settleAfterStop'))->toBeFalse();

    $managerSource = file_get_contents((new ReflectionClass(SnapshotDatabaseManager::class))->getFileName());
    $workerSource = file_get_contents((new ReflectionClass(WorkerRuntime::class))->getFileName());

    expect($managerSource)->not->toContain('settleAfterStop')
        ->and($workerSource)->not->toContain('settleAfterStop')
        ->and($managerSource)->not->toMatch('/usleep\s*\(\s*100_000\s*\)/')
        ->and($workerSource)->not->toMatch('/usleep\s*\(\s*100_000\s*\)/');
});

it('recycle and shutdown stop then delete without an intermediate settle helper', function () {
    $source = file_get_contents((new ReflectionClass(WorkerRuntime::class))->getFileName());

    // recycle: stop() then Dirs::delete(datadir) with no settle call between
    expect($source)->toMatch('/\$this->server->stop\(\);\s*Dirs::delete\(\$this->workerDir\.\'\/datadir\'\);/s');

    // shutdown: stop() in try, delete workerDir in finally — no settle in between
    expect($source)->toMatch(
        '/\$this->server->stop\(\);\s*\}\s*finally\s*\{\s*Dirs::delete\(\$this->workerDir\);/s',
    );
});

it('sweepDeadWorkers never deletes a dir whose mysqld pid is still alive after TERM', function () {
    [$process, $pid] = spawnTermIgnoringStub();
    $this->stubProcess = $process;

    $worker = $this->tmp.'/wdead-mysqld-alive';
    Dirs::ensure($worker.'/datadir');
    // Owner is dead / unknown — only mysqld pid is live.
    file_put_contents($worker.'/owner.pid', '999999999');
    file_put_contents($worker.'/datadir/warp-mysqld.pid', (string) $pid);
    // touch AFTER writes: creating children refreshes dir mtime and would
    // falsely trip the 60s mid-provision guard.
    touch($worker, time() - 120);

    DeadWorkerSweep::run($this->tmp);

    expect(is_dir($worker))->toBeTrue()
        ->and(file_exists($worker.'/datadir/warp-mysqld.pid'))->toBeTrue();
});

it('sweepDeadWorkers never deletes a dir whose owner.pid is still alive', function () {
    $worker = $this->tmp.'/wowner-alive';
    Dirs::ensure($worker.'/datadir');
    file_put_contents($worker.'/owner.pid', (string) getmypid());
    file_put_contents($worker.'/datadir/marker', 'keep');
    touch($worker, time() - 120);

    DeadWorkerSweep::run($this->tmp);

    expect(is_dir($worker))->toBeTrue()
        ->and(file_get_contents($worker.'/datadir/marker'))->toBe('keep');
});

it('sweepDeadWorkers reaps a stale worker dir when owner and mysqld are dead', function () {
    $worker = $this->tmp.'/wstale-dead';
    Dirs::ensure($worker.'/datadir');
    // Dead owner pid (our short-lived one-shot already exited).
    $deadOwner = (int) trim((string) shell_exec(PHP_BINARY.' -r "echo getmypid();"'));
    file_put_contents($worker.'/owner.pid', (string) $deadOwner);
    file_put_contents($worker.'/datadir/warp-mysqld.pid', '0');
    file_put_contents($worker.'/datadir/marker', 'gone');
    touch($worker, time() - 120);

    DeadWorkerSweep::run($this->tmp);

    expect(is_dir($worker))->toBeFalse();
});
