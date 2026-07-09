<?php

declare(strict_types=1);

use RawPHP\Warp\Cli\ShardCommand;
use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Shard\MissingConfigurationException;
use RawPHP\Warp\Shard\SuiteDiscovery;
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
    putenv('WARP_TIMINGS_DIR');
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

it('rejects an empty suffix', function () {
    chdir($this->tmp);
    file_put_contents($this->tmp.'/tests/README.md', 'not a test');

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', 'tests', '--timings-dir='.$this->tmp.'/timings', '--suffix=']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('[warp] --suffix must not be empty');
});

it('uses phpunit xml discovery when no explicit paths are provided', function () {
    chdir($this->tmp);
    Dirs::ensure($this->tmp.'/checks');
    Dirs::ensure($this->tmp.'/explicit');
    Dirs::ensure($this->tmp.'/excluded');
    file_put_contents($this->tmp.'/checks/HealthCheck.php', '<?php');
    file_put_contents($this->tmp.'/checks/IgnoredTest.php', '<?php');
    file_put_contents($this->tmp.'/explicit/ManualSpec.php', '<?php');
    file_put_contents($this->tmp.'/excluded/HiddenTest.php', '<?php');
    file_put_contents($this->tmp.'/tests/SkipMeTest.php', '<?php');
    writeShardPhpunitConfig($this->tmp.'/phpunit.xml', <<<'XML'
        <testsuite name="Unit">
            <directory>tests</directory>
            <directory suffix="Check.php">checks</directory>
            <file>explicit/ManualSpec.php</file>
            <exclude>tests/SkipMeTest.php</exclude>
            <exclude>excluded</exclude>
        </testsuite>
XML);

    $runs = [
        ($this->run)(['1/2', '--timings-dir='.$this->tmp.'/timings']),
        ($this->run)(['2/2', '--timings-dir='.$this->tmp.'/timings']),
    ];

    expect(array_column($runs, 0))->toBe([0, 0]);

    $files = array_values(array_filter(array_merge(
        explode("\n", trim($runs[0][1])),
        explode("\n", trim($runs[1][1])),
    )));
    sort($files);

    expect($files)->toBe([
        'checks/HealthCheck.php',
        'explicit/ManualSpec.php',
        'tests/ATest.php',
        'tests/BTest.php',
        'tests/CTest.php',
        'tests/DTest.php',
    ])
        ->and(implode("\n", array_column($runs, 2)))->toContain('no recorded timings')
        ->and($files)->not->toContain('excluded/HiddenTest.php')
        ->and($files)->not->toContain('tests/SkipMeTest.php');
});

it('does not pre-scan for phpunit xml before suite discovery', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Cli/ShardCommand.php');

    expect($source)->not->toContain('SuiteDiscovery::configurationPath');
});

it('uses the missing-configuration exception type instead of matching exception messages', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Cli/ShardCommand.php');

    expect($source)->toContain('MissingConfigurationException')
        ->and($source)->not->toContain("getMessage() !== '[warp] no phpunit.xml found at project root'")
        ->and($source)->not->toContain('[warp] no phpunit.xml found at project root');
});

it('throws a typed missing-configuration exception when no phpunit xml exists', function () {
    chdir($this->tmp);

    SuiteDiscovery::discover($this->tmp);
})->throws(MissingConfigurationException::class, '[warp] no phpunit.xml found at project root');

it('falls back to tests with a stderr note when no phpunit xml exists', function () {
    chdir($this->tmp);

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe("tests/ATest.php\ntests/CTest.php\n")
        ->and($stderr)->toContain('no phpunit.xml found')
        ->and($stderr)->toContain('falling back to tests/Test.php');
});

it('does not fall back when suite discovery fails for a reason other than missing configuration', function () {
    chdir($this->tmp);
    writeShardPhpunitConfig($this->tmp.'/phpunit.xml', <<<'XML'
        <testsuite name="Missing">
            <directory>missing-tests</directory>
        </testsuite>
XML);

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('[warp] no such test suite directory')
        ->and($stderr)->not->toContain('falling back to tests/Test.php');
});

it('does not fall back when an explicit configuration file is missing', function () {
    chdir($this->tmp);

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', '--configuration=missing.xml', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('[warp] no such configuration file: missing.xml')
        ->and($stderr)->not->toContain('falling back to tests/Test.php');
});

it('bypasses suite discovery when explicit paths are provided', function () {
    chdir($this->tmp);
    Dirs::ensure($this->tmp.'/checks');
    file_put_contents($this->tmp.'/checks/HealthCheck.php', '<?php');
    writeShardPhpunitConfig($this->tmp.'/phpunit.xml', <<<'XML'
        <testsuite name="Checks">
            <directory suffix="Check.php">checks</directory>
        </testsuite>
XML);

    [$exit, $stdout, $stderr] = ($this->run)(['1/1', 'tests', '--configuration=phpunit.xml', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe("tests/ATest.php\ntests/BTest.php\ntests/CTest.php\ntests/DTest.php\n")
        ->and($stderr)->toContain('no recorded timings')
        ->and($stdout)->not->toContain('checks/HealthCheck.php');
});

it('honours an explicit phpunit configuration file', function () {
    chdir($this->tmp);
    Dirs::ensure($this->tmp.'/checks');
    file_put_contents($this->tmp.'/checks/HealthCheck.php', '<?php');
    writeShardPhpunitConfig($this->tmp.'/custom.xml', <<<'XML'
        <testsuite name="Checks">
            <directory suffix="Check.php">checks</directory>
        </testsuite>
XML);

    [$exit, $stdout, $stderr] = ($this->run)(['1/1', '--configuration=custom.xml', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe("checks/HealthCheck.php\n")
        ->and($stderr)->toContain('no recorded timings');
});

it('uses WARP_TIMINGS_DIR when no timings-dir flag is provided', function () {
    chdir($this->tmp);
    $envDir = $this->tmp.'/env-timings';
    putenv('WARP_TIMINGS_DIR='.$envDir);

    (new TimingStore($envDir))->writePending([
        't1' => ['file' => 'tests/ATest.php', 'ms' => 100.0],
        't2' => ['file' => 'tests/BTest.php', 'ms' => 10.0],
        't3' => ['file' => 'tests/CTest.php', 'ms' => 10.0],
        't4' => ['file' => 'tests/DTest.php', 'ms' => 10.0],
    ]);

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', 'tests']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe("tests/ATest.php\n")
        ->and($stderr)->toBe('');
});

it('lets timings-dir override WARP_TIMINGS_DIR', function () {
    chdir($this->tmp);
    putenv('WARP_TIMINGS_DIR='.$this->tmp.'/env-timings');
    $flagDir = $this->tmp.'/flag-timings';

    (new TimingStore($flagDir))->writePending([
        't1' => ['file' => 'tests/ATest.php', 'ms' => 100.0],
        't2' => ['file' => 'tests/BTest.php', 'ms' => 10.0],
        't3' => ['file' => 'tests/CTest.php', 'ms' => 10.0],
        't4' => ['file' => 'tests/DTest.php', 'ms' => 10.0],
    ]);

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', 'tests', '--timings-dir='.$flagDir]);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe("tests/ATest.php\n")
        ->and($stderr)->toBe('');
});

it('rejects an empty timings-dir', function () {
    chdir($this->tmp);

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', 'tests', '--timings-dir=']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('[warp] --timings-dir must not be empty');
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

it('rejects unknown options instead of treating them as paths', function () {
    chdir($this->tmp);

    [$exit, $stdout, $stderr] = ($this->run)(['1/8', '--timigs-dir=/x', 'tests']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('[warp] unknown option: --timigs-dir=/x')
        ->and($stderr)->not->toContain('no such test path');
});

it('returns 3 and prints nothing when the shard is empty', function () {
    chdir($this->tmp);

    [$exit, $stdout, $stderr] = ($this->run)(['6/6', $this->tmp.'/tests', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(3)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('is empty');
});

function writeShardPhpunitConfig(string $path, string $testsuite): void
{
    file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
{$testsuite}
    </testsuites>
</phpunit>
XML);
}
