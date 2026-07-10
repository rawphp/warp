<?php

declare(strict_types=1);

use RawPHP\Warp\Cli\ShardCommand;
use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Timing\TimingCollector;
use RawPHP\Warp\Timing\TimingExtension;
use RawPHP\Warp\Timing\TimingStore;

it('records per-test timings with file attribution from a real pest run', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $root = dirname(__DIR__, 3);

    // WARP_MODE/WARP_DB pinned off so putenv leakage from other suites can't
    // reach the child; WARP_TIMINGS drives the extension under test.
    exec(sprintf(
        'cd %s && WARP_MODE=0 WARP_DB=0 WARP_TIMINGS=1 WARP_TIMINGS_DIR=%s ./vendor/bin/pest tests/Unit/WarpModeTest.php 2>&1',
        escapeshellarg($root),
        escapeshellarg($dir),
    ), $output, $exit);

    $this->assertSame(0, $exit, implode(PHP_EOL, $output));

    $tests = (new TimingStore($dir))->load();

    // WarpModeTest holds ~35 tests after Task 1 (datasets expand to distinct ids).
    expect(count($tests))->toBeGreaterThan(20);

    foreach ($tests as $id => $entry) {
        expect($entry['file'])->toBe('tests/Unit/WarpModeTest.php')
            ->and($entry['ms'])->toBeGreaterThanOrEqual(0.0);
    }

    $pending = glob($dir.'/pending/*.json');

    expect($pending)->toHaveCount(1)
        ->and(is_file($dir.'/timings.json'))->toBeFalse()
        ->and(is_file($dir.'/merge.lock'))->toBeFalse();

    Dirs::delete($dir);
});

it('keys timings against the config dir so a cross-cwd shard intersects (finding 1)', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $suite = sys_get_temp_dir().'/warp-suite-'.bin2hex(random_bytes(4));
    $root = dirname(__DIR__, 3);
    $bootstrap = writeTimingRestrictionBootstrap();

    Dirs::ensure($suite.'/tests');
    file_put_contents($suite.'/tests/AlphaTest.php', <<<'PHP'
<?php

it('alpha timing', function () {
    expect(true)->toBeTrue();
});
PHP);
    file_put_contents($suite.'/tests/BetaTest.php', <<<'PHP'
<?php

it('beta timing', function () {
    expect(true)->toBeTrue();
});
PHP);

    $config = $suite.'/phpunit.xml';
    file_put_contents($config, sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="%s" colors="true">
    <testsuites>
        <testsuite name="CrossDir">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <extensions>
        <bootstrap class="RawPHP\Warp\Timing\TimingExtension"/>
    </extensions>
</phpunit>
XML,
        htmlspecialchars($bootstrap, ENT_XML1),
    ));

    try {
        // Record with a cwd (the package root) that differs from the config dir.
        exec(sprintf(
            'cd %s && WARP_MODE=0 WARP_DB=0 WARP_TIMINGS=1 WARP_TIMINGS_DIR=%s ./vendor/bin/pest --configuration=%s 2>&1',
            escapeshellarg($root),
            escapeshellarg($dir),
            escapeshellarg($config),
        ), $output, $exit);

        expect($exit)->toBe(0, implode(PHP_EOL, $output));

        // Keys are relative to the config dir, not the record-time cwd.
        $tests = (new TimingStore($dir))->load();
        $files = array_values(array_unique(array_column($tests, 'file')));
        sort($files);

        expect($files)->toBe(['tests/AlphaTest.php', 'tests/BetaTest.php']);

        (new TimingStore($dir))->mergeToDisk();

        // Shard from the same cwd with the same --configuration: keys must intersect.
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $previous = getcwd();
        chdir($root);

        try {
            $shardExit = ShardCommand::run(
                ['1/2', '--configuration='.$config, '--timings-dir='.$dir],
                $stdout,
                $stderr,
            );
        } finally {
            chdir($previous);
        }

        rewind($stdout);
        rewind($stderr);
        $shardOut = stream_get_contents($stdout);
        $shardErr = stream_get_contents($stderr);

        expect($shardExit)->toBe(0)
            ->and($shardOut)->not->toBe('')
            ->and($shardErr)->not->toContain('recorded timings match no discovered file')
            ->and($shardErr)->not->toContain('root mismatch');
    } finally {
        Dirs::delete($dir);
        Dirs::delete($suite);
        @unlink($bootstrap);
    }
});

it('does not supersede sibling timings from method-filtered captures', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixture = writeTimingRestrictionFixture();
    $fixtureKey = 'tests/Integration/Timing/RestrictionFixtureTest.php';

    try {
        seedTimings($dir, [
            'Seeded::sibling' => ['file' => $fixtureKey, 'ms' => 1234.0],
        ]);

        runPestWithTimings($dir, [$fixture, '--filter=records first restriction fixture timing']);

        // Only one of the file's two enumerated tests terminated, so the file is
        // flagged incomplete and never supersedes its stale sibling.
        expect(pendingCompleteMaps($dir)[0][$fixtureKey] ?? null)->toBeFalse();

        $store = new TimingStore($dir);
        $store->mergeToDisk();

        $tests = $store->load();

        expect($tests)->toHaveKey('Seeded::sibling')
            ->and($tests['Seeded::sibling']['ms'])->toBe(1234.0);
    } finally {
        @unlink($fixture);
        Dirs::delete($dir);
    }
});

it('marks a whole-file explicit path capture complete so stale sibling ids are pruned', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixture = writeTimingRestrictionFixture();
    $fixtureKey = 'tests/Integration/Timing/RestrictionFixtureTest.php';

    try {
        seedTimings($dir, [
            'Seeded::sibling' => ['file' => $fixtureKey, 'ms' => 4321.0],
        ]);

        // An explicit path runs every enumerated test of the file, so per-file
        // accounting flags it complete and prunes the stale renamed sibling id.
        runPestWithTimings($dir, [$fixture]);

        expect(pendingCompleteMaps($dir)[0][$fixtureKey] ?? null)->toBeTrue();

        $store = new TimingStore($dir);
        $store->mergeToDisk();

        $tests = $store->load();

        expect($tests)->not->toHaveKey('Seeded::sibling');
    } finally {
        @unlink($fixture);
        Dirs::delete($dir);
    }
});

it('keeps testsuite-only captures complete so stale ids are pruned', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixture = writeTimingRestrictionFixture();
    $config = writeTimingRestrictionPhpunitConfig($fixture);
    $fixtureKey = 'tests/Integration/Timing/RestrictionFixtureTest.php';

    try {
        seedTimings($dir, [
            'Seeded::stale' => ['file' => $fixtureKey, 'ms' => 9999.0],
        ]);

        runPestWithTimings($dir, ['--configuration='.$config, '--testsuite=RestrictionFixture']);

        expect(pendingCompleteMaps($dir)[0][$fixtureKey] ?? null)->toBeTrue();

        $store = new TimingStore($dir);
        $store->mergeToDisk();

        $tests = $store->load();

        expect($tests)->not->toHaveKey('Seeded::stale');
    } finally {
        @unlink($config);
        @unlink($config.'.php');
        @unlink($fixture);
        Dirs::delete($dir);
    }
});

it('attributes inherited classic phpunit test methods to concrete test files', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixtures = writeTimingInheritedPhpunitFixtures();
    $config = writeTimingInheritedPhpunitConfig($fixtures['one'], $fixtures['two']);

    try {
        runPestWithTimings($dir, ['--configuration='.$config, '--testsuite=InheritedTimingFixture']);

        $tests = (new TimingStore($dir))->load();
        $filesById = [];

        foreach ($tests as $id => $entry) {
            if (str_contains($id, 'WarpInheritedTimingOneTest') || str_contains($id, 'WarpInheritedTimingTwoTest')) {
                $filesById[$id] = $entry['file'];
            }
        }

        expect($filesById)->toHaveCount(4)
            ->and(array_unique(array_values($filesById)))->sequence(
                fn ($file) => $file->toBe('tests/Integration/Timing/InheritedTimingOneTest.php'),
                fn ($file) => $file->toBe('tests/Integration/Timing/InheritedTimingTwoTest.php'),
            );

        foreach ($filesById as $id => $file) {
            if (str_contains($id, 'WarpInheritedTimingOneTest')) {
                expect($file)->toBe('tests/Integration/Timing/InheritedTimingOneTest.php');
            }

            if (str_contains($id, 'WarpInheritedTimingTwoTest')) {
                expect($file)->toBe('tests/Integration/Timing/InheritedTimingTwoTest.php');
            }
        }
    } finally {
        @unlink($config);
        @unlink($config.'.php');

        foreach ($fixtures as $fixture) {
            @unlink($fixture);
        }

        Dirs::delete($dir);
    }
});

it('marks stop-on-failure captures incomplete so unrun sibling timings survive', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixture = writeTimingEarlyStopFixture();
    $config = writeTimingEarlyStopPhpunitConfig($fixture);
    $fixtureKey = 'tests/Integration/Timing/EarlyStopFixtureTest.php';

    try {
        seedTimings($dir, [
            'Seeded::unrunSibling' => ['file' => $fixtureKey, 'ms' => 2468.0],
        ]);

        $result = runPestWithTimingsExpectingFailure($dir, ['--configuration='.$config, '--testsuite=EarlyStopFixture']);

        expect($result['exit'])->toBe(1)
            ->and(implode(PHP_EOL, $result['output']))->toContain('fails and stops early');

        $payload = pendingPayloads($dir)[0] ?? null;
        $fixtureKeyKey = 'tests/Integration/Timing/EarlyStopFixtureTest.php';

        expect($payload)->toBeArray()
            ->and($payload['complete'][$fixtureKeyKey] ?? null)->toBeFalse()
            ->and($payload['tests'] ?? [])->toHaveCount(2);

        $recordedIds = implode("\n", array_keys($payload['tests']));

        expect($recordedIds)->toContain('__pest_evaluable_it_passes_before_early_stop')
            ->and($recordedIds)->toContain('__pest_evaluable_it_fails_and_stops_early')
            ->and($recordedIds)->not->toContain('__pest_evaluable_it_does_not_run_after_early_stop');

        $store = new TimingStore($dir);
        $store->mergeToDisk();

        $tests = $store->load();

        expect($tests)->toHaveKey('Seeded::unrunSibling')
            ->and($tests['Seeded::unrunSibling']['ms'])->toBe(2468.0);
    } finally {
        @unlink($config);
        @unlink($config.'.php');
        @unlink($fixture);
        Dirs::delete($dir);
    }
});

it('keeps successful stop-on-failure testsuite captures complete so stale ids are pruned', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixture = writeTimingPassingStopOnFixture();
    $config = writeTimingEarlyStopPhpunitConfig($fixture, suiteName: 'PassingStopOnFixture');
    $fixtureKey = 'tests/Integration/Timing/PassingStopOnFixtureTest.php';

    try {
        seedTimings($dir, [
            'Seeded::staleStopOn' => ['file' => $fixtureKey, 'ms' => 1357.0],
        ]);

        runPestWithTimings($dir, ['--configuration='.$config, '--testsuite=PassingStopOnFixture']);

        expect(pendingCompleteMaps($dir)[0][$fixtureKey] ?? null)->toBeTrue();

        $store = new TimingStore($dir);
        $store->mergeToDisk();

        $tests = $store->load();

        expect($tests)->not->toHaveKey('Seeded::staleStopOn');
    } finally {
        @unlink($config);
        @unlink($config.'.php');
        @unlink($fixture);
        Dirs::delete($dir);
    }
});

it('marks stop-on-defect captures incomplete when a defect stops the run', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixture = writeTimingEarlyStopFixture();
    $config = writeTimingEarlyStopPhpunitConfig($fixture, 'stopOnDefect');
    $fixtureKey = 'tests/Integration/Timing/EarlyStopFixtureTest.php';

    try {
        seedTimings($dir, [
            'Seeded::unrunSibling' => ['file' => $fixtureKey, 'ms' => 2468.0],
        ]);

        $result = runPestWithTimingsExpectingFailure($dir, ['--configuration='.$config, '--testsuite=EarlyStopFixture']);

        expect($result['exit'])->toBe(1)
            ->and(implode(PHP_EOL, $result['output']))->toContain('fails and stops early')
            ->and(pendingCompleteMaps($dir)[0][$fixtureKey] ?? null)->toBeFalse();

        $store = new TimingStore($dir);
        $store->mergeToDisk();

        $tests = $store->load();

        expect($tests)->toHaveKey('Seeded::unrunSibling')
            ->and($tests['Seeded::unrunSibling']['ms'])->toBe(2468.0);
    } finally {
        @unlink($config);
        @unlink($config.'.php');
        @unlink($fixture);
        Dirs::delete($dir);
    }
});

it('marks stop-on-error captures incomplete when an error stops the run', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixture = writeTimingEarlyStopFixture('error');
    $config = writeTimingEarlyStopPhpunitConfig($fixture, 'stopOnError');
    $fixtureKey = 'tests/Integration/Timing/EarlyStopFixtureTest.php';

    try {
        seedTimings($dir, [
            'Seeded::unrunSibling' => ['file' => $fixtureKey, 'ms' => 2468.0],
        ]);

        $result = runPestWithTimingsExpectingFailure($dir, ['--configuration='.$config, '--testsuite=EarlyStopFixture']);

        expect($result['exit'])->toBe(2)
            ->and(implode(PHP_EOL, $result['output']))->toContain('errors and stops early')
            ->and(pendingCompleteMaps($dir)[0][$fixtureKey] ?? null)->toBeFalse();

        $store = new TimingStore($dir);
        $store->mergeToDisk();

        $tests = $store->load();

        expect($tests)->toHaveKey('Seeded::unrunSibling')
            ->and($tests['Seeded::unrunSibling']['ms'])->toBe(2468.0);
    } finally {
        @unlink($config);
        @unlink($config.'.php');
        @unlink($fixture);
        Dirs::delete($dir);
    }
});

it('shutdown backstop capture supersedes stale entries for fully observed files', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));

    Dirs::ensure($dir);
    file_put_contents($dir.'/timings.json', json_encode([
        'version' => 3,
        'tests' => [
            'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
            'FileA::staleRenamed' => ['file' => 'tests/FileATest.php', 'ms' => 5000.0],
        ],
    ], JSON_THROW_ON_ERROR));

    $collector = new TimingCollector;
    $collector->enumerated('FileA::one', 'tests/FileATest.php');
    $collector->started('FileA::one', 1.0);
    $collector->finished('FileA::one', 'tests/FileATest.php', 1.25);

    $store = new TimingStore($dir);
    backstopFlush($collector, $store);
    $store->mergeToDisk();

    $collector = new TimingCollector;
    $collector->enumerated('FileA::one', 'tests/FileATest.php');
    $collector->started('FileA::one', 2.0);
    $collector->finished('FileA::one', 'tests/FileATest.php', 2.5);

    backstopFlush($collector, $store);
    $store->mergeToDisk();

    expect($store->load())->toBe([
        'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 500.0],
    ]);

    Dirs::delete($dir);
});

it('shutdown backstop capture with an in-flight test does not supersede sibling timings', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));

    Dirs::ensure($dir);
    file_put_contents($dir.'/timings.json', json_encode([
        'version' => 3,
        'tests' => [
            'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
            'FileA::sibling' => ['file' => 'tests/FileATest.php', 'ms' => 5000.0],
        ],
    ], JSON_THROW_ON_ERROR));

    // The file's second test is enumerated (from Loaded) but the process dies
    // before it terminates, so the file stays incomplete and only upserts.
    $collector = new TimingCollector;
    $collector->enumerated('FileA::one', 'tests/FileATest.php');
    $collector->enumerated('FileA::two', 'tests/FileATest.php');
    $collector->started('FileA::one', 1.0);
    $collector->finished('FileA::one', 'tests/FileATest.php', 1.25);
    $collector->started('FileA::two', 1.3);

    $store = new TimingStore($dir);
    backstopFlush($collector, $store);
    $store->mergeToDisk();

    expect(pendingCompleteMaps($dir))->toBe([])
        ->and($store->load())->toBe([
            'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 250.0],
            'FileA::sibling' => ['file' => 'tests/FileATest.php', 'ms' => 5000.0],
        ]);

    Dirs::delete($dir);
});

it('keeps passing child runs green and warns per failed flush attempt (REQ-100)', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixture = writeTimingRestrictionFixture();

    try {
        Dirs::ensure($dir);
        chmod($dir, 0555);

        $result = runPestWithTimingsResult($dir, [$fixture]);
        $output = implode(PHP_EOL, $result['output']);

        // The timings dir stays unwritable for the whole run, so both the
        // ExecutionFinished flush and the shutdown-backstop retry genuinely fail
        // (REQ-100: hasFlushed() only becomes true after a successful write, so
        // the backstop no longer skips the retry). Two failed attempts, two
        // warnings — the REQ-082 nonfatal contract holds per attempt, not once
        // per run.
        expect($result['exit'])->toBe(0)
            ->and(substr_count($output, '[warp] timing flush failed:'))->toBe(2)
            ->and($output)->toContain('[warp] cannot create directory')
            ->and($output)->not->toContain('Fatal error')
            ->and($output)->not->toContain('exit code 255')
            ->and(glob($dir.'/pending/*.json') ?: [])->toHaveCount(0);
    } finally {
        @chmod($dir, 0755);
        @unlink($fixture);
        Dirs::delete($dir);
    }
});

it('leaves no trace when WARP_TIMINGS is off', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $root = dirname(__DIR__, 3);

    exec(sprintf(
        'cd %s && WARP_MODE=0 WARP_DB=0 WARP_TIMINGS_DIR=%s ./vendor/bin/pest tests/Unit/WarpModeTest.php 2>&1',
        escapeshellarg($root),
        escapeshellarg($dir),
    ), $output, $exit);

    $this->assertSame(0, $exit, implode(PHP_EOL, $output));

    expect(is_dir($dir))->toBeFalse();
});

it('marks a run halted by a stop-on flag outside the old list incomplete so the interrupted file survives (finding 4)', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixture = writeTimingIncompleteStopFixture();
    $config = writeTimingEarlyStopPhpunitConfig($fixture, 'stopOnIncomplete', 'IncompleteStopFixture');
    $fixtureKey = 'tests/Integration/Timing/IncompleteStopFixtureTest.php';

    try {
        seedTimings($dir, [
            'Seeded::unrunSibling' => ['file' => $fixtureKey, 'ms' => 2468.0],
        ]);

        // stopOnIncomplete is one of the flags the old stop-on sniffer missed
        // (it only knew stopOnDefect/Error/Failure), so it used to flush
        // complete=true and supersede the file's full timings. Per-file event
        // accounting leaves the interrupted file incomplete regardless of which
        // stop-on flag halted the run - no flag list required.
        $result = runPestWithTimingsResult($dir, ['--configuration='.$config, '--testsuite=IncompleteStopFixture']);

        expect(implode(PHP_EOL, $result['output']))->toContain('is incomplete and stops early')
            ->and(pendingCompleteMaps($dir)[0][$fixtureKey] ?? null)->toBeFalse();

        $store = new TimingStore($dir);
        $store->mergeToDisk();

        $tests = $store->load();

        expect($tests)->toHaveKey('Seeded::unrunSibling')
            ->and($tests['Seeded::unrunSibling']['ms'])->toBe(2468.0);
    } finally {
        @unlink($config);
        @unlink($config.'.php');
        @unlink($fixture);
        Dirs::delete($dir);
    }
});

it('reaches end-of-run with zero in-flight entries for a suite mixing .phpt and setUp skips (findings 5 and 16)', function () {
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $suite = sys_get_temp_dir().'/warp-phpt-suite-'.bin2hex(random_bytes(4));
    $bootstrap = writeTimingRestrictionBootstrap();

    Dirs::ensure($suite);
    file_put_contents($suite.'/SkipSetupTest.php', <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

final class WarpSkipSetupTest extends TestCase
{
    protected function setUp(): void
    {
        if ($this->name() === 'testSkippedInSetup') {
            $this->markTestSkipped('skip in setUp');
        }
    }

    public function testRunsNormally(): void
    {
        $this->assertTrue(true);
    }

    public function testSkippedInSetup(): void
    {
        $this->fail('should never run');
    }
}
PHP);
    file_put_contents($suite.'/example.phpt', <<<'PHPT'
--TEST--
warp phpt fixture
--FILE--
<?php
echo 'warp-phpt-ok';
--EXPECT--
warp-phpt-ok
PHPT);

    $config = $suite.'/phpunit.xml';
    file_put_contents($config, sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="%s" colors="true">
    <testsuites>
        <testsuite name="PhptSkipSuite">
            <directory>.</directory>
            <file>example.phpt</file>
        </testsuite>
    </testsuites>
    <extensions>
        <bootstrap class="RawPHP\Warp\Timing\TimingExtension"/>
    </extensions>
</phpunit>
XML,
        htmlspecialchars($bootstrap, ENT_XML1),
    ));

    try {
        exec(sprintf(
            'cd %s && WARP_MODE=0 WARP_DB=0 WARP_TIMINGS=1 WARP_TIMINGS_DIR=%s %s --configuration=%s 2>&1',
            escapeshellarg($suite),
            escapeshellarg($dir),
            escapeshellarg($root.'/vendor/bin/phpunit'),
            escapeshellarg($config),
        ), $output, $exit);

        $map = pendingCompleteMaps($dir)[0] ?? null;
        $tests = pendingPayloads($dir)[0]['tests'] ?? [];

        // No enumerated file leaked an in-flight entry: neither the .phpt (which
        // is not a TestMethod) nor the setUp-skip left the file incomplete.
        expect($map)->toBeArray()
            ->and(in_array(false, $map, true))->toBeFalse()
            ->and($map['SkipSetupTest.php'] ?? null)->toBeTrue()
            ->and($map['example.phpt'] ?? null)->toBeTrue();

        // The fully-run method still recorded a timing under its file.
        $files = array_column($tests, 'file');
        expect($files)->toContain('SkipSetupTest.php');
    } finally {
        Dirs::delete($dir);
        Dirs::delete($suite);
        @unlink($bootstrap);
    }
});

it('flushes a pending batch through the shutdown backstop when a test exits mid-run (untested backstop gap)', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixture = writeTimingExitMidRunFixture();
    $fixtureKey = 'tests/Integration/Timing/ExitMidRunFixtureTest.php';

    try {
        // The second test exit()s, so ExecutionFinished never fires and only the
        // register_shutdown_function backstop can flush. The first test's timing
        // is captured, but the file is incomplete (later tests never terminated).
        $result = runPestWithTimingsResult($dir, [$fixture]);

        $maps = pendingCompleteMaps($dir);
        $tests = pendingPayloads($dir)[0]['tests'] ?? [];

        expect($maps)->toHaveCount(1)
            ->and($maps[0][$fixtureKey] ?? null)->toBeFalse()
            ->and(array_column($tests, 'file'))->toContain($fixtureKey);
    } finally {
        @unlink($fixture);
        Dirs::delete($dir);
    }
});

it('records telemetry duration for errored-unprepared tests so the file keeps real weight after supersede (finding 5)', function () {
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $suite = sys_get_temp_dir().'/warp-errored-setup-suite-'.bin2hex(random_bytes(4));
    $bootstrap = writeTimingRestrictionBootstrap();
    $fixtureKey = 'ErroredSetupFixtureTest.php';
    $erroredInSetupOne = 'WarpErroredSetupFixtureTest::testErrorsInSetupOne';
    $erroredInSetupTwo = 'WarpErroredSetupFixtureTest::testErrorsInSetupTwo';

    Dirs::ensure($suite);
    file_put_contents($suite.'/ErroredSetupFixtureTest.php', <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

final class WarpErroredSetupFixtureTest extends TestCase
{
    protected function setUp(): void
    {
        // Simulates finding 5's illustrative case (a 60s DB timeout in setUp):
        // real, non-trivial work happens before the throw, before Test\Prepared
        // ever fires - Test\Finished is gated on wasPrepared() and never fires
        // for these two tests.
        if (str_starts_with($this->name(), 'testErrorsInSetup')) {
            usleep(15_000);

            throw new RuntimeException('simulated setUp failure (e.g. DB timeout)');
        }
    }

    public function testPassesNormally(): void
    {
        $this->assertTrue(true);
    }

    public function testErrorsInSetupOne(): void
    {
        $this->assertTrue(true);
    }

    public function testErrorsInSetupTwo(): void
    {
        $this->assertTrue(true);
    }

    public function testErrorsAfterPreparation(): void
    {
        // setUp succeeds for this test (name doesn't match testErrorsInSetup*),
        // so it IS prepared; the body then errors. Test\Finished still fires and
        // must remain the sole source of this test's duration - Errored firing
        // too must not double-record it.
        throw new RuntimeException('simulated failure after setUp succeeded');
    }
}
PHP);

    $config = $suite.'/phpunit.xml';
    file_put_contents($config, sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="%s" colors="true">
    <testsuites>
        <testsuite name="ErroredSetupFixture">
            <directory>.</directory>
        </testsuite>
    </testsuites>
    <extensions>
        <bootstrap class="RawPHP\Warp\Timing\TimingExtension"/>
    </extensions>
</phpunit>
XML,
        htmlspecialchars($bootstrap, ENT_XML1),
    ));

    // Prior real timings for the file, seeded before the run - this is what
    // findings 5's supersede-on-complete defect would wipe to nothing.
    Dirs::ensure($dir);
    file_put_contents($dir.'/timings.json', json_encode([
        'version' => 3,
        'tests' => [
            'Seeded::stale' => ['file' => $fixtureKey, 'ms' => 99999.0],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        exec(sprintf(
            'cd %s && WARP_MODE=0 WARP_DB=0 WARP_TIMINGS=1 WARP_TIMINGS_DIR=%s %s --configuration=%s 2>&1',
            escapeshellarg($suite),
            escapeshellarg($dir),
            escapeshellarg($root.'/vendor/bin/phpunit'),
            escapeshellarg($config),
        ), $output, $exit);

        expect($exit)->toBe(2, implode(PHP_EOL, $output));

        $map = pendingCompleteMaps($dir)[0] ?? null;

        expect($map[$fixtureKey] ?? null)->toBeTrue();

        $payload = pendingPayloads($dir)[0];
        $recorded = $payload['tests'] ?? [];

        // The never-prepared tests must carry a real (nonzero) recorded duration,
        // not be silently missing (pre-fix, they never appeared here at all).
        expect($recorded)->toHaveKeys([$erroredInSetupOne, $erroredInSetupTwo])
            ->and($recorded[$erroredInSetupOne]['ms'])->toBeGreaterThan(0.0)
            ->and($recorded[$erroredInSetupTwo]['ms'])->toBeGreaterThan(0.0);

        $store = new TimingStore($dir);
        $store->mergeToDisk();

        $tests = $store->load();

        // Complete-file supersede replaced the stale seeded entry entirely, but
        // with the errored tests' real telemetry-derived durations, not with
        // nothing - the file's total weight after supersede is nonzero.
        expect($tests)->not->toHaveKey('Seeded::stale')
            ->and($tests)->toHaveKeys([$erroredInSetupOne, $erroredInSetupTwo]);

        $totalForFile = array_sum(array_map(
            static fn (array $entry): float => $entry['ms'],
            array_filter($tests, static fn (array $entry): bool => $entry['file'] === $fixtureKey),
        ));

        expect($totalForFile)->toBeGreaterThan(0.0);
    } finally {
        @unlink($config);
        Dirs::delete($suite);
        Dirs::delete($dir);
        @unlink($bootstrap);
    }
});

function writeTimingIncompleteStopFixture(): string
{
    $path = dirname(__DIR__).'/Timing/IncompleteStopFixtureTest.php';

    file_put_contents($path, <<<'PHP'
<?php

it('passes before incomplete stop', function () {
    expect(true)->toBeTrue();
});

it('is incomplete and stops early', function () {
    $this->markTestIncomplete('warp stop-on-incomplete fixture');
});

it('does not run after incomplete stop', function () {
    expect(true)->toBeTrue();
});
PHP);

    return $path;
}

function writeTimingExitMidRunFixture(): string
{
    $path = dirname(__DIR__).'/Timing/ExitMidRunFixtureTest.php';

    file_put_contents($path, <<<'PHP'
<?php

it('passes before mid run exit', function () {
    expect(true)->toBeTrue();
});

it('exits mid run', function () {
    exit(0);
});

it('never runs after mid run exit', function () {
    expect(true)->toBeTrue();
});
PHP);

    return $path;
}

/**
 * @param  array<string, array{file: string, ms: float}>  $tests
 */
function seedTimings(string $dir, array $tests): void
{
    Dirs::ensure($dir);
    file_put_contents($dir.'/timings.json', json_encode([
        'version' => 3,
        'tests' => $tests,
    ], JSON_THROW_ON_ERROR));
}

function writeTimingRestrictionFixture(): string
{
    $path = dirname(__DIR__).'/Timing/RestrictionFixtureTest.php';

    file_put_contents($path, <<<'PHP'
<?php

it('records first restriction fixture timing', function () {
    expect(true)->toBeTrue();
});

it('records second restriction fixture timing', function () {
    expect(true)->toBeTrue();
});
PHP);

    return $path;
}

function writeTimingRestrictionPhpunitConfig(string $fixture): string
{
    $path = dirname(__DIR__, 3).'/warp-phpunit-'.bin2hex(random_bytes(4)).'.xml';
    $bootstrap = writeTimingRestrictionBootstrap($path.'.php');

    file_put_contents($path, sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="%s" colors="true">
    <testsuites>
        <testsuite name="RestrictionFixture">
            <file>%s</file>
        </testsuite>
    </testsuites>
    <extensions>
        <bootstrap class="RawPHP\Warp\Timing\TimingExtension"/>
    </extensions>
</phpunit>
XML,
        htmlspecialchars($bootstrap, ENT_XML1),
        htmlspecialchars($fixture, ENT_XML1),
    ));

    return $path;
}

/**
 * @return array{base: string, trait: string, one: string, two: string}
 */
function writeTimingInheritedPhpunitFixtures(): array
{
    $directory = dirname(__DIR__).'/Timing';
    $base = $directory.'/InheritedTimingBase.php';
    $trait = $directory.'/InheritedTimingTrait.php';
    $one = $directory.'/InheritedTimingOneTest.php';
    $two = $directory.'/InheritedTimingTwoTest.php';

    file_put_contents($base, <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

abstract class WarpInheritedTimingBaseTest extends TestCase
{
    public function testInheritedBaseTiming(): void
    {
        self::assertTrue(true);
    }
}
PHP);
    file_put_contents($trait, <<<'PHP'
<?php

trait WarpInheritedTimingTrait
{
    public function testInheritedTraitTiming(): void
    {
        self::assertTrue(true);
    }
}
PHP);
    file_put_contents($one, sprintf(<<<'PHP'
<?php

require_once %s;
require_once %s;

final class WarpInheritedTimingOneTest extends WarpInheritedTimingBaseTest
{
    use WarpInheritedTimingTrait;
}
PHP,
        var_export($base, true),
        var_export($trait, true),
    ));
    file_put_contents($two, sprintf(<<<'PHP'
<?php

require_once %s;
require_once %s;

final class WarpInheritedTimingTwoTest extends WarpInheritedTimingBaseTest
{
    use WarpInheritedTimingTrait;
}
PHP,
        var_export($base, true),
        var_export($trait, true),
    ));

    return ['base' => $base, 'trait' => $trait, 'one' => $one, 'two' => $two];
}

function writeTimingInheritedPhpunitConfig(string $one, string $two): string
{
    $path = dirname(__DIR__, 3).'/warp-phpunit-'.bin2hex(random_bytes(4)).'.xml';
    $bootstrap = writeTimingRestrictionBootstrap($path.'.php');

    file_put_contents($path, sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="%s" colors="true">
    <testsuites>
        <testsuite name="InheritedTimingFixture">
            <file>%s</file>
            <file>%s</file>
        </testsuite>
    </testsuites>
    <extensions>
        <bootstrap class="RawPHP\Warp\Timing\TimingExtension"/>
    </extensions>
</phpunit>
XML,
        htmlspecialchars($bootstrap, ENT_XML1),
        htmlspecialchars($one, ENT_XML1),
        htmlspecialchars($two, ENT_XML1),
    ));

    return $path;
}

function writeTimingEarlyStopFixture(string $mode = 'failure'): string
{
    $path = dirname(__DIR__).'/Timing/EarlyStopFixtureTest.php';

    if ($mode === 'error') {
        file_put_contents($path, <<<'PHP'
<?php

it('passes before early stop', function () {
    expect(true)->toBeTrue();
});

it('errors and stops early', function () {
    throw new RuntimeException('stop-on-error fixture');
});

it('does not run after early stop', function () {
    expect(true)->toBeTrue();
});
PHP);

        return $path;
    }

    file_put_contents($path, <<<'PHP'
<?php

it('passes before early stop', function () {
    expect(true)->toBeTrue();
});

it('fails and stops early', function () {
    expect(false)->toBeTrue();
});

it('does not run after early stop', function () {
    expect(true)->toBeTrue();
});
PHP);

    return $path;
}

function writeTimingPassingStopOnFixture(): string
{
    $path = dirname(__DIR__).'/Timing/PassingStopOnFixtureTest.php';

    file_put_contents($path, <<<'PHP'
<?php

it('passes first stop-on fixture timing', function () {
    expect(true)->toBeTrue();
});

it('passes second stop-on fixture timing', function () {
    expect(true)->toBeTrue();
});
PHP);

    return $path;
}

function writeTimingEarlyStopPhpunitConfig(
    string $fixture,
    string $stopAttribute = 'stopOnFailure',
    string $suiteName = 'EarlyStopFixture',
): string {
    $path = dirname(__DIR__, 3).'/warp-phpunit-'.bin2hex(random_bytes(4)).'.xml';
    $bootstrap = writeTimingRestrictionBootstrap($path.'.php');

    file_put_contents($path, sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="%s" colors="true" %s="true">
    <testsuites>
        <testsuite name="%s">
            <file>%s</file>
        </testsuite>
    </testsuites>
    <extensions>
        <bootstrap class="RawPHP\Warp\Timing\TimingExtension"/>
    </extensions>
</phpunit>
XML,
        htmlspecialchars($bootstrap, ENT_XML1),
        htmlspecialchars($stopAttribute, ENT_XML1),
        htmlspecialchars($suiteName, ENT_XML1),
        htmlspecialchars($fixture, ENT_XML1),
    ));

    return $path;
}

/**
 * @param  list<string>  $arguments
 */
function runPestWithTimings(string $dir, array $arguments): void
{
    $result = runPestWithTimingsResult($dir, $arguments);

    test()->assertSame(0, $result['exit'], implode(PHP_EOL, $result['output']));
}

/**
 * @param  list<string>  $arguments
 * @return array{exit: int, output: list<string>}
 */
function runPestWithTimingsResult(string $dir, array $arguments): array
{
    $root = dirname(__DIR__, 3);
    $bootstrap = writeTimingRestrictionBootstrap();

    try {
        $command = sprintf(
            'cd %s && WARP_MODE=0 WARP_DB=0 WARP_TIMINGS=1 WARP_TIMINGS_DIR=%s ./vendor/bin/pest --bootstrap=%s %s 2>&1',
            escapeshellarg($root),
            escapeshellarg($dir),
            escapeshellarg($bootstrap),
            implode(' ', array_map('escapeshellarg', $arguments)),
        );

        exec($command, $output, $exit);

        return ['exit' => $exit, 'output' => $output];
    } finally {
        @unlink($bootstrap);
    }
}

/**
 * @param  list<string>  $arguments
 * @return array{exit: int, output: list<string>}
 */
function runPestWithTimingsExpectingFailure(string $dir, array $arguments): array
{
    $root = dirname(__DIR__, 3);

    $command = sprintf(
        'cd %s && WARP_MODE=0 WARP_DB=0 WARP_TIMINGS=1 WARP_TIMINGS_DIR=%s ./vendor/bin/pest %s 2>&1',
        escapeshellarg($root),
        escapeshellarg($dir),
        implode(' ', array_map('escapeshellarg', $arguments)),
    );

    exec($command, $output, $exit);

    return ['exit' => $exit, 'output' => $output];
}

function writeTimingRestrictionBootstrap(?string $path = null): string
{
    $root = dirname(__DIR__, 3);
    $path ??= sys_get_temp_dir().'/warp-bootstrap-'.bin2hex(random_bytes(4)).'.php';

    file_put_contents($path, sprintf(<<<'PHP'
<?php

$loader = require %s;
$loader->addPsr4('RawPHP\\Warp\\', %s, true);
PHP,
        var_export($root.'/vendor/autoload.php', true),
        var_export($root.'/src', true),
    ));

    return $path;
}

/**
 * The per-file completeness maps carried by each pending batch, in glob order.
 *
 * @return list<array<string, bool>>
 */
function pendingCompleteMaps(string $dir): array
{
    return array_map(
        static fn (array $payload): array => is_array($payload['complete'] ?? null) ? $payload['complete'] : [],
        pendingPayloads($dir),
    );
}

/** @return list<array<string, mixed>> */
function pendingPayloads(string $dir): array
{
    $payloads = [];

    foreach (glob($dir.'/pending/*.json') ?: [] as $path) {
        $payloads[] = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    return $payloads;
}

/** Flush a collector through the extension's static backstop path (per-file accounting). */
function backstopFlush(TimingCollector $collector, TimingStore $store): void
{
    (new ReflectionMethod(TimingExtension::class, 'flush'))->invoke(null, $collector, $store);
}
