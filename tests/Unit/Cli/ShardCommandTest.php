<?php

declare(strict_types=1);

use RawPHP\Warp\Cli\ShardCommand;
use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Timing\TimingStore;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/warp-shardcmd-'.bin2hex(random_bytes(4));
    Dirs::ensure($this->tmp.'/tests');
    file_put_contents($this->tmp.'/tests/ATest.php', '<?php');
    file_put_contents($this->tmp.'/tests/BTest.php', '<?php');
    file_put_contents($this->tmp.'/tests/CTest.php', '<?php');
    file_put_contents($this->tmp.'/tests/DTest.php', '<?php');

    $this->run = function (array $args): array {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $exit = ShardCommand::run($args, $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);

        return [$exit, stream_get_contents($stdout), stream_get_contents($stderr)];
    };
});

afterEach(function () {
    Dirs::delete($this->tmp);
});

it('prints a duration-balanced shard using recorded timings', function () {
    (new TimingStore($this->tmp.'/timings'))->writePending([
        't1' => ['file' => $this->tmp.'/tests/ATest.php', 'ms' => 100.0],
        't2' => ['file' => $this->tmp.'/tests/BTest.php', 'ms' => 10.0],
        't3' => ['file' => $this->tmp.'/tests/CTest.php', 'ms' => 10.0],
        't4' => ['file' => $this->tmp.'/tests/DTest.php', 'ms' => 10.0],
    ]);

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', $this->tmp.'/tests', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe($this->tmp."/tests/ATest.php\n")
        ->and($stderr)->toBe('');
});

it('falls back to count-balanced with a warning when no timings exist', function () {
    [$exit, $stdout, $stderr] = ($this->run)(['1/2', $this->tmp.'/tests', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe($this->tmp."/tests/ATest.php\n".$this->tmp."/tests/CTest.php\n")
        ->and($stderr)->toContain('no recorded timings');
});

it('honours a custom suffix', function () {
    file_put_contents($this->tmp.'/tests/spec_a.php', '<?php');

    [$exit, $stdout] = ($this->run)(['1/1', $this->tmp.'/tests', '--timings-dir='.$this->tmp.'/timings', '--suffix=spec_a.php']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe($this->tmp."/tests/spec_a.php\n");
});

it('returns 2 with usage when the shard spec is missing', function () {
    [$exit, $stdout, $stderr] = ($this->run)([$this->tmp.'/tests']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('usage: warp shard');
});

it('returns 2 on a missing test path', function () {
    [$exit, , $stderr] = ($this->run)(['1/2', $this->tmp.'/nope']);

    expect($exit)->toBe(2)
        ->and($stderr)->toContain('[warp] no such test path');
});

it('returns 2 on an out-of-range shard index', function () {
    [$exit, , $stderr] = ($this->run)(['5/2', $this->tmp.'/tests']);

    expect($exit)->toBe(2)
        ->and($stderr)->toContain('[warp] shard index out of range');
});

it('returns 3 and prints nothing when the shard is empty', function () {
    [$exit, $stdout, $stderr] = ($this->run)(['6/6', $this->tmp.'/tests', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(3)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('is empty');
});
