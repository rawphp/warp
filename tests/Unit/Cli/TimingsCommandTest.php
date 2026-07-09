<?php

declare(strict_types=1);

use RawPHP\Warp\Cli\TimingsCommand;
use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Timing\TimingStore;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/warp-timingscmd-'.bin2hex(random_bytes(4));

    $this->run = function (array $args): array {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $exit = TimingsCommand::run($args, $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);

        return [$exit, stream_get_contents($stdout), stream_get_contents($stderr)];
    };
});

afterEach(function () {
    putenv('WARP_TIMINGS_DIR');
    Dirs::delete($this->tmp);
});

it('reports counts, total, and slowest files', function () {
    (new TimingStore($this->tmp))->writePending([
        't1' => ['file' => 'tests/SlowTest.php', 'ms' => 900.0],
        't2' => ['file' => 'tests/SlowTest.php', 'ms' => 100.0],
        't3' => ['file' => 'tests/FastTest.php', 'ms' => 50.0],
    ]);

    [$exit, $stdout] = ($this->run)(['--timings-dir='.$this->tmp]);

    expect($exit)->toBe(0)
        ->and($stdout)->toContain('3 tests across 2 files')
        ->and($stdout)->toContain('1050.0ms')
        ->and(strpos($stdout, 'SlowTest'))->toBeLessThan(strpos($stdout, 'FastTest'));
});

it('says so when nothing was recorded', function () {
    [$exit, $stdout] = ($this->run)(['--timings-dir='.$this->tmp]);

    expect($exit)->toBe(0)
        ->and($stdout)->toContain('no timings recorded yet');
});

it('uses WARP_TIMINGS_DIR when no timings-dir flag is provided', function () {
    putenv('WARP_TIMINGS_DIR='.$this->tmp);

    (new TimingStore($this->tmp))->writePending([
        't1' => ['file' => 'tests/SlowTest.php', 'ms' => 900.0],
    ]);

    [$exit, $stdout, $stderr] = ($this->run)([]);

    expect($exit)->toBe(0)
        ->and($stdout)->toContain('1 tests across 1 files')
        ->and($stderr)->toBe('');
});

it('lets timings-dir override WARP_TIMINGS_DIR', function () {
    $envDir = $this->tmp.'/env';
    $flagDir = $this->tmp.'/flag';
    putenv('WARP_TIMINGS_DIR='.$envDir);

    (new TimingStore($envDir))->writePending([
        'env' => ['file' => 'tests/EnvTest.php', 'ms' => 1.0],
    ]);
    (new TimingStore($flagDir))->writePending([
        'flag' => ['file' => 'tests/FlagTest.php', 'ms' => 1.0],
    ]);

    [$exit, $stdout, $stderr] = ($this->run)(['--timings-dir='.$flagDir]);

    expect($exit)->toBe(0)
        ->and($stdout)->toContain('FlagTest')
        ->and($stdout)->not->toContain('EnvTest')
        ->and($stderr)->toBe('');
});

it('rejects unknown arguments', function () {
    [$exit, , $stderr] = ($this->run)(['positional']);

    expect($exit)->toBe(2)
        ->and($stderr)->toContain('unknown argument');
});

it('rejects unknown options', function () {
    [$exit, $stdout, $stderr] = ($this->run)(['--bogus']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('[warp] unknown option: --bogus');
});

it('returns 2 without a stack trace when the merged timings file is corrupt', function () {
    Dirs::ensure($this->tmp);
    file_put_contents($this->tmp.'/timings.json', 'not json');

    [$exit, $stdout, $stderr] = ($this->run)(['--timings-dir='.$this->tmp]);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('[warp] cannot decode timings')
        ->and($stderr)->not->toContain('Stack trace');
});
