<?php

declare(strict_types=1);

use RawPHP\Warp\Cli\ShardCommand;
use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Timing\TimingStore;

beforeEach(function () {
    $this->cwd = getcwd();
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
    chdir($this->cwd);
    Dirs::delete($this->tmp);
});

it('prints a duration-balanced shard using recorded timings', function () {
    chdir($this->tmp);

    (new TimingStore($this->tmp.'/timings'))->writePending([
        't1' => ['file' => 'tests/ATest.php', 'ms' => 100.0],
        't2' => ['file' => 'tests/BTest.php', 'ms' => 10.0],
        't3' => ['file' => 'tests/CTest.php', 'ms' => 10.0],
        't4' => ['file' => 'tests/DTest.php', 'ms' => 10.0],
    ]);

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', 'tests', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe("tests/ATest.php\n")
        ->and($stderr)->toBe('');
});

it('produces byte-identical duration-balanced shards for relative, dot-relative, and absolute paths', function () {
    chdir($this->tmp);

    (new TimingStore($this->tmp.'/timings'))->writePending([
        't1' => ['file' => 'tests/ATest.php', 'ms' => 100.0],
        't2' => ['file' => 'tests/BTest.php', 'ms' => 60.0],
        't3' => ['file' => 'tests/CTest.php', 'ms' => 10.0],
        't4' => ['file' => 'tests/DTest.php', 'ms' => 10.0],
    ]);

    $runs = [
        ($this->run)(['1/2', 'tests', '--timings-dir='.$this->tmp.'/timings']),
        ($this->run)(['1/2', './tests', '--timings-dir='.$this->tmp.'/timings']),
        ($this->run)(['1/2', $this->tmp.'/tests', '--timings-dir='.$this->tmp.'/timings']),
    ];

    expect(array_column($runs, 0))->toBe([0, 0, 0])
        ->and(array_column($runs, 1))->toBe([
            "tests/ATest.php\n",
            "tests/ATest.php\n",
            "tests/ATest.php\n",
        ])
        ->and(array_column($runs, 2))->toBe(['', '', '']);
});

it('warns when recorded totals match no discovered canonical file', function () {
    chdir($this->tmp);

    (new TimingStore($this->tmp.'/timings'))->writePending([
        't1' => ['file' => 'tests/GoneTest.php', 'ms' => 100.0],
    ]);

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', 'tests', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe("tests/ATest.php\ntests/CTest.php\n")
        ->and($stderr)->toContain('recorded timings match no discovered file')
        ->and($stderr)->toContain('count-balanced');
});

it('falls back to count-balanced with a warning when no timings exist', function () {
    chdir($this->tmp);

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', $this->tmp.'/tests', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe("tests/ATest.php\ntests/CTest.php\n")
        ->and($stderr)->toContain('no recorded timings');
});

it('honours a custom suffix', function () {
    chdir($this->tmp);
    file_put_contents($this->tmp.'/tests/spec_a.php', '<?php');

    [$exit, $stdout] = ($this->run)(['1/1', 'tests', '--timings-dir='.$this->tmp.'/timings', '--suffix=spec_a.php']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe("tests/spec_a.php\n");
});

it('returns 2 with usage when the shard spec is missing', function () {
    [$exit, $stdout, $stderr] = ($this->run)([$this->tmp.'/tests']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('usage: warp shard');
});

it('returns 2 on a missing test path', function () {
    chdir($this->tmp);

    [$exit, , $stderr] = ($this->run)(['1/2', $this->tmp.'/nope']);

    expect($exit)->toBe(2)
        ->and($stderr)->toContain('[warp] no such test path');
});

it('returns 2 on an out-of-range shard index', function () {
    chdir($this->tmp);

    [$exit, , $stderr] = ($this->run)(['5/2', $this->tmp.'/tests']);

    expect($exit)->toBe(2)
        ->and($stderr)->toContain('[warp] shard index out of range');
});

it('returns 3 and prints nothing when the shard is empty', function () {
    chdir($this->tmp);

    [$exit, $stdout, $stderr] = ($this->run)(['6/6', $this->tmp.'/tests', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(3)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('is empty');
});
