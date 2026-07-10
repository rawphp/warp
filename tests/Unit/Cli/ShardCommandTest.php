<?php

declare(strict_types=1);

use RawPHP\Warp\Cli\ShardCommand;
use RawPHP\Warp\Cli\WarpCli;
use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Shard\MissingConfigurationException;
use RawPHP\Warp\Shard\SuiteDiscovery;
use RawPHP\Warp\Shard\TestFileFinder;
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
        $exit = WarpCli::run(['warp', 'shard', ...$args], $stdout, $stderr);
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

it('exits 2 when suite discovery finds no test files', function () {
    chdir($this->tmp);
    Dirs::ensure($this->tmp.'/empty-tests');
    writeShardPhpunitConfig($this->tmp.'/phpunit.xml', <<<'XML'
        <testsuite name="Empty">
            <directory>empty-tests</directory>
        </testsuite>
XML);

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toBe("[warp] no test files discovered - nothing to shard\n");
});

it('exits 2 when explicit paths match no test files', function () {
    chdir($this->tmp);

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', 'tests', '--timings-dir='.$this->tmp.'/timings', '--suffix=Spec.php']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toBe("[warp] no test files discovered - nothing to shard\n");
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

it('warns when suffix is ignored by phpunit xml discovery', function () {
    chdir($this->tmp);
    file_put_contents($this->tmp.'/tests/OnlySpec.php', '<?php');
    writeShardPhpunitConfig($this->tmp.'/phpunit.xml', <<<'XML'
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
XML);

    [$exit, $stdout, $stderr] = ($this->run)(['1/1', '--suffix=Spec.php', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe("tests/ATest.php\ntests/BTest.php\ntests/CTest.php\ntests/DTest.php\n")
        ->and($stdout)->not->toContain('suffix')
        ->and($stderr)->toContain('[warp] --suffix=Spec.php ignored because phpunit.xml discovery controls test file suffixes')
        ->and($stderr)->toContain('no recorded timings');
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

it('shards an explicit outside-root path with no phpunit.xml present, without aborting (finding 8, explicit-path mode)', function () {
    chdir($this->tmp);
    $external = sys_get_temp_dir().'/warp-shardcmd-outside-explicit-'.bin2hex(random_bytes(4));
    Dirs::ensure($external.'/tests');
    file_put_contents($external.'/tests/OutsideTest.php', '<?php');

    try {
        // No phpunit.xml anywhere: rootConfigurationPath resolves null, so the
        // canonical root stays cwd and the file is genuinely outside it. Pre-fix
        // this exited 2 with "test path is outside project root".
        [$exit, $stdout, $stderr] = ($this->run)(['1/1', $external.'/tests', '--timings-dir='.$this->tmp.'/timings']);

        expect($exit)->toBe(0)
            ->and($stderr)->not->toContain('outside project root')
            ->and($stdout)->toBe('../'.basename($external)."/tests/OutsideTest.php\n");
    } finally {
        Dirs::delete($external);
    }
});

it('shards a symlinked tests/ directory pointing outside the root with no phpunit.xml present, without aborting (finding 8, no-phpunit.xml fallback mode)', function () {
    // Replace the beforeEach-created tests/ dir with a symlink to an external
    // directory, so the tests/-fallback discovers files that resolve outside
    // the project root. Pre-fix, this fallback branch left allowOutsideRoot
    // false and exited 2, even though the exact same layout shards fine once a
    // phpunit.xml is present (config-driven discovery already allowed it).
    Dirs::delete($this->tmp.'/tests');
    $external = sys_get_temp_dir().'/warp-shardcmd-outside-fallback-'.bin2hex(random_bytes(4));
    Dirs::ensure($external);
    file_put_contents($external.'/OutsideTest.php', '<?php');
    symlink($external, $this->tmp.'/tests');

    chdir($this->tmp);

    try {
        [$exit, $stdout, $stderr] = ($this->run)(['1/1', '--timings-dir='.$this->tmp.'/timings']);

        expect($exit)->toBe(0)
            ->and($stderr)->not->toContain('outside project root')
            ->and($stderr)->toContain('no phpunit.xml found')
            ->and($stdout)->toBe('../'.basename($external)."/OutsideTest.php\n");
    } finally {
        Dirs::delete($external);
    }
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
    writeShardPhpunitConfig($this->tmp.'/custom.xml', <<<'XML'
        <testsuite name="Checks">
            <directory suffix="Check.php">checks</directory>
        </testsuite>
XML);

    [$exit, $stdout, $stderr] = ($this->run)(['1/1', 'tests', '--configuration=custom.xml', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe("tests/ATest.php\ntests/BTest.php\ntests/CTest.php\ntests/DTest.php\n")
        ->and($stdout)->not->toContain('configuration')
        ->and($stderr)->toContain('[warp] --configuration=custom.xml ignored for suite discovery')
        ->and($stderr)->toContain('discovery')
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

it('canonicalizes explicit configuration suites relative to the configuration directory', function () {
    $app = $this->tmp.'/app';
    $runner = $this->tmp.'/runner';
    Dirs::ensure($app.'/tests');
    Dirs::ensure($runner);
    file_put_contents($app.'/tests/AppTest.php', '<?php');
    writeShardPhpunitConfig($app.'/phpunit.xml', <<<'XML'
        <testsuite name="App">
            <directory>tests</directory>
        </testsuite>
XML);

    chdir($runner);

    [$exit, $stdout, $stderr] = ($this->run)(['1/1', '--configuration='.$app.'/phpunit.xml', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->toBe("tests/AppTest.php\n")
        ->and($stderr)->toContain('no recorded timings')
        ->and($stderr)->not->toContain('outside project root');
});

it('emits a root-relative ../ key for symlinked suite files outside the configuration root (finding 8, UR-017 key unification)', function () {
    chdir($this->tmp);
    $external = sys_get_temp_dir().'/warp-shardcmd-external-'.bin2hex(random_bytes(4));
    Dirs::ensure($external);
    file_put_contents($external.'/SharedTest.php', '<?php');
    symlink($external, $this->tmp.'/tests/Shared');
    writeShardPhpunitConfig($this->tmp.'/phpunit.xml', <<<'XML'
        <testsuite name="Symlinked">
            <directory>tests/Shared</directory>
        </testsuite>
XML);
    // Both $this->tmp and $external are direct siblings under sys_get_temp_dir(),
    // so the relative key is one level up plus the external dir's own name -
    // identical regardless of the absolute temp-dir prefix on another machine.
    $sharedKey = '../'.basename($external).'/SharedTest.php';

    (new TimingStore($this->tmp.'/timings'))->writePending([
        'shared' => ['file' => $sharedKey, 'ms' => 100.0],
    ]);

    try {
        [$exit, $stdout, $stderr] = ($this->run)(['1/1', '--timings-dir='.$this->tmp.'/timings']);

        expect($exit)->toBe(0)
            ->and($stdout)->toBe($sharedKey."\n")
            ->and($stderr)->not->toContain('outside project root')
            ->and($stderr)->not->toContain('recorded timings match no discovered file');
    } finally {
        Dirs::delete($external);
    }
});

it('exits non-zero and names both roots when the recorded root differs from the shard-time root', function () {
    chdir($this->tmp);
    writeShardPhpunitConfig($this->tmp.'/phpunit.xml', <<<'XML'
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
XML);

    $store = (new TimingStore($this->tmp.'/timings'))->withRoot('/recorded/elsewhere');
    $store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 100.0]]);
    $store->mergeToDisk();

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', '--configuration=phpunit.xml', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->not->toBe(0)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('/recorded/elsewhere')
        ->and($stderr)->toContain((string) realpath($this->tmp))
        ->and($stderr)->toContain('root');
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

it('documents --configuration in the shard usage output (finding 21)', function () {
    [$exit, $stdout, $stderr] = ($this->run)([$this->tmp.'/tests']);

    expect($exit)->toBe(2)
        ->and($stderr)->toContain('usage: warp shard')
        ->and($stderr)->toContain('--configuration=');
});

it('sources both usage strings from one shared definition that documents --configuration (finding 21)', function () {
    $root = dirname(__DIR__, 3);
    $shardSrc = (string) file_get_contents($root.'/src/Cli/ShardCommand.php');
    $cliSrc = (string) file_get_contents($root.'/src/Cli/WarpCli.php');

    // One shared constant, documenting --configuration, referenced by both.
    expect(ShardCommand::USAGE)
        ->toContain('warp shard')
        ->toContain('--configuration=');
    expect($cliSrc)
        ->toContain('ShardCommand::USAGE')
        ->not->toContain('warp shard <index>/<total>');
    expect($shardSrc)->toContain('const USAGE');
});

it('bench reuses ShardCommand canonicalization instead of forking it (finding 20)', function () {
    $root = dirname(__DIR__, 3);
    $bench = (string) file_get_contents($root.'/bench/shard-spread.php');

    expect($bench)
        ->toContain('ShardCommand::canonicalFiles(')
        ->not->toContain('could not resolve real path for test file');

    // The exception string lives exactly once across src/ and bench/.
    $occurrences = 0;
    foreach (['src', 'bench'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $occurrences += substr_count((string) file_get_contents($file->getPathname()), 'could not resolve real path for test file');
            }
        }
    }

    expect($occurrences)->toBe(1);
});

it('sources default shard suffixes from TestFileFinder::DEFAULT_SUFFIXES rather than a copy (finding 22)', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Cli/ShardCommand.php');

    expect($source)
        ->toContain('TestFileFinder::DEFAULT_SUFFIXES')
        ->not->toContain("['Test.php', '.phpt']");

    $const = new ReflectionClassConstant(TestFileFinder::class, 'DEFAULT_SUFFIXES');
    expect($const->isPublic())->toBeTrue()
        ->and(TestFileFinder::DEFAULT_SUFFIXES)->toBe(['Test.php', '.phpt']);
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

it('rejects a shard total above the sane ceiling with a bounds diagnostic', function () {
    chdir($this->tmp);

    [$exit, $stdout, $stderr] = ($this->run)(['1/20000', 'tests', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('[warp] shard total out of range')
        ->and($stderr)->toContain('10000');
});

it('keeps range diagnostics for out-of-range indices within a valid total', function () {
    chdir($this->tmp);

    [$lowExit, , $lowStderr] = ($this->run)(['0/4', $this->tmp.'/tests', '--timings-dir='.$this->tmp.'/timings']);
    [$highExit, , $highStderr] = ($this->run)(['5/4', $this->tmp.'/tests', '--timings-dir='.$this->tmp.'/timings']);

    expect($lowExit)->toBe(2)
        ->and($lowStderr)->toContain('[warp] shard index out of range')
        ->and($highExit)->toBe(2)
        ->and($highStderr)->toContain('[warp] shard index out of range');
});

it('rejects unknown options instead of treating them as paths', function () {
    chdir($this->tmp);

    [$exit, $stdout, $stderr] = ($this->run)(['1/8', '--timigs-dir=/x', 'tests']);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('[warp] unknown option: --timigs-dir=/x')
        ->and($stderr)->not->toContain('no such test path');
});

it('covers a symlink, a .phpt file, and excludes a hidden-dir decoy across the union of shards', function () {
    chdir($this->tmp);

    Dirs::ensure($this->tmp.'/shared');
    file_put_contents($this->tmp.'/shared/SharedTest.php', '<?php');
    symlink($this->tmp.'/shared', $this->tmp.'/tests/Linked');

    file_put_contents($this->tmp.'/tests/example.phpt', "--TEST--\nExample\n--FILE--\n<?php\n--EXPECT--\n");

    Dirs::ensure($this->tmp.'/tests/.cache');
    file_put_contents($this->tmp.'/tests/.cache/StaleTest.php', '<?php');

    $runs = [
        ($this->run)(['1/3', 'tests', '--timings-dir='.$this->tmp.'/timings']),
        ($this->run)(['2/3', 'tests', '--timings-dir='.$this->tmp.'/timings']),
        ($this->run)(['3/3', 'tests', '--timings-dir='.$this->tmp.'/timings']),
    ];

    expect(array_column($runs, 0))->toBe([0, 0, 0]);

    $union = array_values(array_filter(array_merge(
        explode("\n", trim($runs[0][1])),
        explode("\n", trim($runs[1][1])),
        explode("\n", trim($runs[2][1])),
    )));
    sort($union);

    // ShardCommand canonicalizes discovered files via realpath() against the
    // project root (Paths::canonical), so a file reached through a symlinked
    // directory is reported under its real (target) path, not the symlink
    // name it was discovered through.
    expect($union)->toBe([
        'shared/SharedTest.php',
        'tests/ATest.php',
        'tests/BTest.php',
        'tests/CTest.php',
        'tests/DTest.php',
        'tests/example.phpt',
    ])
        ->and(count($union))->toBe(count(array_unique($union)));
});

it('returns 3 and prints nothing when the shard is empty', function () {
    chdir($this->tmp);

    [$exit, $stdout, $stderr] = ($this->run)(['6/6', $this->tmp.'/tests', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(3)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('is empty');
});

it('records and shards with identical roots for a symlinked implicit phpunit.xml (finding 4)', function () {
    // The real config lives in a sibling dir; cwd exposes it through a symlink.
    // Pre-fix, the read side used raw getcwd() while the write side stamped
    // dirname(realpath(configFile)), so the symlink diverged the two roots and
    // every shard exited 2 on a false mismatch.
    $realConfigDir = $this->tmp.'/realconfig';
    Dirs::ensure($realConfigDir);
    file_put_contents($realConfigDir.'/phpunit.xml', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Linked">
            <directory>{$this->tmp}/tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);

    chdir($this->tmp);
    symlink($realConfigDir.'/phpunit.xml', $this->tmp.'/phpunit.xml');

    $configRoot = (string) realpath($realConfigDir);

    // The extension would stamp dirname(realpath(phpunit.xml)) = the REAL config
    // dir. tests/ is a sibling of realconfig/, so both sides compute the same
    // root-relative ../ key for it (UR-017 key unification).
    $store = (new TimingStore($this->tmp.'/timings'))->withRoot($configRoot);
    $store->writePending([
        't1' => ['file' => '../tests/ATest.php', 'ms' => 100.0],
        't2' => ['file' => '../tests/BTest.php', 'ms' => 10.0],
        't3' => ['file' => '../tests/CTest.php', 'ms' => 10.0],
        't4' => ['file' => '../tests/DTest.php', 'ms' => 10.0],
    ]);
    $store->mergeToDisk();

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stderr)->not->toContain('root mismatch')
        ->and($stderr)->not->toContain('recorded timings match no discovered file')
        ->and($stdout)->toContain('../tests/ATest.php');
});

it('honours --configuration for the timing-key root in explicit-path mode (finding 9)', function () {
    Dirs::ensure($this->tmp.'/config');
    writeShardPhpunitConfig($this->tmp.'/config/phpunit.xml', <<<'XML'
        <testsuite name="Unit">
            <directory>../tests</directory>
        </testsuite>
XML);

    chdir($this->tmp);

    $configRoot = (string) realpath($this->tmp.'/config');

    // Timings were recorded against the config dir (root=<project>/config). With
    // explicit paths the shard bypasses discovery but must still resolve keys
    // against the config dir, so the recorded and shard-time roots agree. tests/
    // is a sibling of config/, so both sides compute the same root-relative ../
    // key for it (UR-017 key unification).
    $store = (new TimingStore($this->tmp.'/timings'))->withRoot($configRoot);
    $store->writePending([
        't1' => ['file' => '../tests/ATest.php', 'ms' => 100.0],
        't2' => ['file' => '../tests/BTest.php', 'ms' => 10.0],
        't3' => ['file' => '../tests/CTest.php', 'ms' => 10.0],
        't4' => ['file' => '../tests/DTest.php', 'ms' => 10.0],
    ]);
    $store->mergeToDisk();

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', 'tests', '--configuration=config/phpunit.xml', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->not->toBe('')
        ->and($stdout)->toContain('../tests/ATest.php')
        ->and($stderr)->toContain('ignored for suite discovery')
        ->and($stderr)->not->toContain('root mismatch')
        ->and($stderr)->not->toContain('recorded timings match no discovered file');
});

it('degrades to count-balanced with a warning when a stale artifact root matches no discovered file (finding 7)', function () {
    chdir($this->tmp);
    writeShardPhpunitConfig($this->tmp.'/phpunit.xml', <<<'XML'
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
XML);

    // Recorded against a renamed workspace path with keys that match nothing here
    // (pure stale/foreign artifact, e.g. a restored CI cache from another path).
    $store = (new TimingStore($this->tmp.'/timings'))->withRoot('/ci/old/workspace');
    $store->writePending(['t1' => ['file' => 'src/Legacy/GoneTest.php', 'ms' => 100.0]]);
    $store->mergeToDisk();

    [$exit, $stdout, $stderr] = ($this->run)(['1/2', '--configuration=phpunit.xml', '--timings-dir='.$this->tmp.'/timings']);

    expect($exit)->toBe(0)
        ->and($stdout)->not->toBe('')
        ->and($stderr)->toContain('/ci/old/workspace')
        ->and($stderr)->toContain((string) realpath($this->tmp))
        ->and($stderr)->toContain('count-balanced');
});

it('shards via the lockless fallback when the timings dir is read-only, producing the same plan as a writable dir (UR-011 guarantee)', function () {
    chdir($this->tmp);

    $seedTimings = function (string $dir): void {
        Dirs::ensure($dir.'/pending');
        file_put_contents($dir.'/timings.json', json_encode([
            'version' => 3,
            'tests' => ['old' => ['file' => 'tests/ATest.php', 'ms' => 100.0]],
        ]));
        file_put_contents($dir.'/pending/100-1-aaaaaaaa.json', json_encode([
            'complete' => true,
            'tests' => ['fresh' => ['file' => 'tests/BTest.php', 'ms' => 60.0]],
        ]));
    };

    $writableDir = $this->tmp.'/timings-writable';
    $readOnlyDir = $this->tmp.'/timings-readonly';
    $seedTimings($writableDir);
    $seedTimings($readOnlyDir);

    $before = shardTimingsDirDigest($readOnlyDir);

    chmod($readOnlyDir.'/pending', 0555);
    chmod($readOnlyDir, 0555);

    try {
        $writableRun = ($this->run)(['1/2', 'tests', '--timings-dir='.$writableDir]);
        $readOnlyRun = ($this->run)(['1/2', 'tests', '--timings-dir='.$readOnlyDir]);
    } finally {
        chmod($readOnlyDir, 0755);
        chmod($readOnlyDir.'/pending', 0755);
    }

    $after = shardTimingsDirDigest($readOnlyDir);

    // The read-only dir (UR-011 CI-artifact-restore guarantee) must produce
    // exit 0 via the lockless fallback with the identical duration-balanced
    // plan a writable dir with the same contents would produce, and must be
    // byte-identical afterwards - no merge.lock, no pending mutation.
    expect($readOnlyRun[0])->toBe(0)
        ->and($readOnlyRun)->toBe($writableRun)
        ->and($after)->toBe($before)
        ->and(is_file($readOnlyDir.'/merge.lock'))->toBeFalse();
});

function shardTimingsDirDigest(string $dir): string
{
    $entries = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $path => $info) {
        $relative = substr((string) $path, strlen($dir));
        $entries[$relative] = $info->isDir() ? 'DIR' : md5_file((string) $path);
    }

    ksort($entries);

    return md5(json_encode($entries, JSON_THROW_ON_ERROR));
}

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
