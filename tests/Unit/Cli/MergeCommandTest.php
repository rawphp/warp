<?php

declare(strict_types=1);

use RawPHP\Warp\Cli\MergeCommand;
use RawPHP\Warp\Cli\WarpCli;
use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Timing\TimingStore;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/warp-mergecmd-'.bin2hex(random_bytes(4));

    $this->run = function (array $args): array {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $exit = MergeCommand::run($args, $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);

        return [$exit, stream_get_contents($stdout), stream_get_contents($stderr)];
    };
});

afterEach(function () {
    Dirs::delete($this->tmp);
});

it('merges pending batches to disk and reports when nothing remains', function () {
    $store = new TimingStore($this->tmp);
    $store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.0]]);
    $store->writePending(['t2' => ['file' => 'tests/BTest.php', 'ms' => 20.0]]);

    [$firstExit, $firstStdout, $firstStderr] = ($this->run)(['--timings-dir='.$this->tmp]);
    [$secondExit, $secondStdout, $secondStderr] = ($this->run)(['--timings-dir='.$this->tmp]);

    expect($firstExit)->toBe(0)
        ->and($firstStdout)->toContain('merged 2 pending timing batches')
        ->and($firstStderr)->toBe('')
        ->and(glob($this->tmp.'/pending/*.json'))->toBe([])
        ->and((new TimingStore($this->tmp))->load())->toBe([
            't1' => ['file' => 'tests/ATest.php', 'ms' => 10.0],
            't2' => ['file' => 'tests/BTest.php', 'ms' => 20.0],
        ])
        ->and($secondExit)->toBe(0)
        ->and($secondStdout)->toContain('nothing to merge')
        ->and($secondStderr)->toBe('');
});

it('returns 2 with a warp-prefixed error when the merge lock cannot be opened', function () {
    $store = new TimingStore($this->tmp);
    $store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.0]]);
    mkdir($this->tmp.'/merge.lock');

    [$exit, $stdout, $stderr] = ($this->run)(['--timings-dir='.$this->tmp]);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('[warp] cannot open file lock')
        ->and($stderr)->not->toContain('Stack trace');
});

it('rejects unknown arguments', function () {
    [$exit, $stdout, $stderr] = ($this->run)(['--bogus']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('[warp] unknown argument');
});

it('lists the merge command in usage output', function () {
    $stdout = fopen('php://memory', 'r+');
    $stderr = fopen('php://memory', 'r+');

    $exit = WarpCli::run(['warp'], $stdout, $stderr);

    rewind($stdout);
    rewind($stderr);

    expect($exit)->toBe(2)
        ->and(stream_get_contents($stdout))->toBe('')
        ->and(stream_get_contents($stderr))->toContain('warp merge [--timings-dir=DIR]');
});
