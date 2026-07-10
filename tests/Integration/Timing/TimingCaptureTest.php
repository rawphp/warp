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

        expect(pendingCompletenessFlags($dir))->toBe([false]);

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

it('marks explicit path captures incomplete so sibling timings survive', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixture = writeTimingRestrictionFixture();
    $fixtureKey = 'tests/Integration/Timing/RestrictionFixtureTest.php';

    try {
        seedTimings($dir, [
            'Seeded::sibling' => ['file' => $fixtureKey, 'ms' => 4321.0],
        ]);

        runPestWithTimings($dir, [$fixture]);

        expect(pendingCompletenessFlags($dir))->toBe([false]);

        $store = new TimingStore($dir);
        $store->mergeToDisk();

        $tests = $store->load();

        expect($tests)->toHaveKey('Seeded::sibling')
            ->and($tests['Seeded::sibling']['ms'])->toBe(4321.0);
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

        expect(pendingCompletenessFlags($dir))->toBe([true]);

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

        expect($payload)->toBeArray()
            ->and($payload['complete'] ?? null)->toBeFalse()
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

        expect(pendingCompletenessFlags($dir))->toBe([true]);

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
            ->and(pendingCompletenessFlags($dir))->toBe([false]);

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
            ->and(pendingCompletenessFlags($dir))->toBe([false]);

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
        'version' => 2,
        'tests' => [
            'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
            'FileA::staleRenamed' => ['file' => 'tests/FileATest.php', 'ms' => 5000.0],
        ],
    ], JSON_THROW_ON_ERROR));

    $flush = new ReflectionMethod(TimingExtension::class, 'flush');

    $collector = new TimingCollector;
    $collector->started('FileA::one', 1.0);
    $collector->finished('FileA::one', 'tests/FileATest.php', 1.25);

    $store = new TimingStore($dir);
    $flush->invoke(null, $collector, $store, shutdownBackstopComplete($collector));
    $store->mergeToDisk();

    $collector = new TimingCollector;
    $collector->started('FileA::one', 2.0);
    $collector->finished('FileA::one', 'tests/FileATest.php', 2.5);

    $flush->invoke(null, $collector, $store, shutdownBackstopComplete($collector));
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
        'version' => 2,
        'tests' => [
            'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
            'FileA::sibling' => ['file' => 'tests/FileATest.php', 'ms' => 5000.0],
        ],
    ], JSON_THROW_ON_ERROR));

    $flush = new ReflectionMethod(TimingExtension::class, 'flush');
    $collector = new TimingCollector;
    $collector->started('FileA::one', 1.0);
    $collector->finished('FileA::one', 'tests/FileATest.php', 1.25);
    $collector->started('FileA::two', 1.3);

    $store = new TimingStore($dir);
    $flush->invoke(null, $collector, $store, shutdownBackstopComplete($collector));
    $store->mergeToDisk();

    expect(pendingCompletenessFlags($dir))->toBe([])
        ->and($store->load())->toBe([
            'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 250.0],
            'FileA::sibling' => ['file' => 'tests/FileATest.php', 'ms' => 5000.0],
        ]);

    Dirs::delete($dir);
});

it('shutdown backstop completeness remains incomplete after fatal shutdowns', function () {
    $collector = new TimingCollector;
    $collector->started('FileA::one', 1.0);
    $collector->finished('FileA::one', 'tests/FileATest.php', 1.25);

    expect(shutdownBackstopComplete($collector, hadFatalError: true))->toBeFalse();
});

it('keeps passing child runs green and warns once when timing flush fails', function () {
    $dir = sys_get_temp_dir().'/warp-capture-'.bin2hex(random_bytes(4));
    $fixture = writeTimingRestrictionFixture();

    try {
        Dirs::ensure($dir);
        chmod($dir, 0555);

        $result = runPestWithTimingsResult($dir, [$fixture]);
        $output = implode(PHP_EOL, $result['output']);

        expect($result['exit'])->toBe(0)
            ->and(substr_count($output, '[warp] timing flush failed:'))->toBe(1)
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

/**
 * @param  array<string, array{file: string, ms: float}>  $tests
 */
function seedTimings(string $dir, array $tests): void
{
    Dirs::ensure($dir);
    file_put_contents($dir.'/timings.json', json_encode([
        'version' => 2,
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

/** @return list<bool> */
function pendingCompletenessFlags(string $dir): array
{
    return array_map(
        static fn (array $payload): ?bool => $payload['complete'] ?? null,
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

function shutdownBackstopComplete(
    TimingCollector $collector,
    bool $completeRun = true,
    bool $hadFatalError = false,
): bool {
    $method = new ReflectionMethod(TimingExtension::class, 'shutdownBackstopComplete');

    return $method->invoke(null, $collector, $completeRun, $hadFatalError);
}
