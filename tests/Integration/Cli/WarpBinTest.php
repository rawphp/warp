<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Shard\TestFileFinder;
use RawPHP\Warp\Timing\TimingStore;

/** @return array{0: int, 1: string, 2: string} [exit, stdout, stderr] */
function warpBinRun(array $args, ?string $cwd = null): array
{
    $root = dirname(__DIR__, 3);

    $process = proc_open(
        ['php', $root.'/bin/warp', ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd ?? $root,
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    return [proc_close($process), (string) $stdout, (string) $stderr];
}

/** @return array{0: int, 1: string, 2: string} [exit, stdout, stderr] */
function shellRun(string $script, string $cwd): array
{
    $process = proc_open(
        ['sh', '-e', '-c', $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    return [proc_close($process), (string) $stdout, (string) $stderr];
}

/** @return array{0: int, 1: string, 2: string} [exit, stdout, stderr] */
function benchShardSpreadRun(array $args, string $cwd): array
{
    $root = dirname(__DIR__, 3);

    $process = proc_open(
        [PHP_BINARY, $root.'/bench/shard-spread.php', ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    return [proc_close($process), (string) $stdout, (string) $stderr];
}

/** @param list<string> $files */
function createWarpFixtureProject(string $dir, array $files = ['ATest.php', 'BTest.php', 'CTest.php']): string
{
    $project = $dir.'/project';
    Dirs::ensure($project.'/tests');

    foreach ($files as $file) {
        file_put_contents($project.'/tests/'.$file, '<?php');
    }

    return $project;
}

function writeTimingArtifactWithPendingOverlay(string $dir): void
{
    Dirs::ensure($dir.'/pending');

    file_put_contents($dir.'/timings.json', json_encode([
        'version' => 3,
        'tests' => [
            'ATest::old' => ['file' => 'tests/ATest.php', 'ms' => 1.0],
            'BTest::old' => ['file' => 'tests/BTest.php', 'ms' => 1.0],
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents($dir.'/pending/100-1-aabbccdd.json', json_encode([
        'complete' => ['tests/ATest.php' => true, 'tests/BTest.php' => true],
        'tests' => [
            'ATest::fresh' => ['file' => 'tests/ATest.php', 'ms' => 100.0],
            'BTest::fresh' => ['file' => 'tests/BTest.php', 'ms' => 1.0],
        ],
    ], JSON_THROW_ON_ERROR));
}

/** @param array<string, float> $fileTotals */
function writeTimingArtifactForFiles(string $dir, array $fileTotals): void
{
    Dirs::ensure($dir);

    $tests = [];

    foreach ($fileTotals as $file => $ms) {
        $tests[$file.'::test'] = ['file' => $file, 'ms' => $ms];
    }

    file_put_contents($dir.'/timings.json', json_encode([
        'version' => 3,
        'tests' => $tests,
    ], JSON_THROW_ON_ERROR));
}

function writeFakePestBinary(string $project, string $body): void
{
    Dirs::ensure($project.'/vendor/bin');

    file_put_contents($project.'/vendor/bin/pest', "#!/usr/bin/env sh\nset -eu\n".$body);
    chmod($project.'/vendor/bin/pest', 0755);
}

/** @return array<string, string> */
function timingArtifactSnapshot(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $relative = substr($path, strlen($dir) + 1);
        $snapshot[$relative] = $entry->isDir()
            ? 'dir'
            : 'file:'.(string) file_get_contents($path);
    }

    ksort($snapshot);

    return $snapshot;
}

function makeTimingArtifactReadOnly(string $dir): void
{
    if (is_dir($dir.'/pending')) {
        chmod($dir.'/pending', 0555);
    }

    chmod($dir, 0555);
}

function makeWritableForDelete(string $path): void
{
    if (! file_exists($path) && ! is_link($path)) {
        return;
    }

    if (! is_dir($path) || is_link($path)) {
        @chmod($path, 0644);

        return;
    }

    @chmod($path, 0755);

    $children = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($children as $child) {
        @chmod($child->getPathname(), $child->isDir() && ! $child->isLink() ? 0755 : 0644);
    }
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/warp-bin-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    makeWritableForDelete($this->dir);
    Dirs::delete($this->dir);
});

it('shards agree, are disjoint, and cover every discovered file', function () {
    (new TimingStore($this->dir))->writePending([
        't1' => ['file' => 'tests/Unit/WarpModeTest.php', 'ms' => 500.5],
    ]);

    [$exit1, $out1] = warpBinRun(['shard', '1/2', 'tests/Unit', '--timings-dir='.$this->dir]);
    [$exit2, $out2] = warpBinRun(['shard', '2/2', 'tests/Unit', '--timings-dir='.$this->dir]);

    expect($exit1)->toBe(0)->and($exit2)->toBe(0);

    $union = [
        ...array_filter(explode("\n", trim($out1))),
        ...array_filter(explode("\n", trim($out2))),
    ];
    sort($union);

    $root = dirname(__DIR__, 3);
    $expected = TestFileFinder::find([$root.'/tests/Unit']);
    $expected = array_map(static fn (string $file): string => substr($file, strlen($root) + 1), $expected);

    expect($union)->toBe($expected)
        ->and(count($union))->toBe(count(array_unique($union)));
});

it('rejects an oversized shard total through the real binary without a fatal or stack trace', function () {
    $project = createWarpFixtureProject($this->dir);
    $timings = $project.'/timings';

    [$hugeExit, $hugeStdout, $hugeStderr] = warpBinRun(
        ['shard', '1/99999999999999999999', 'tests', '--timings-dir='.$timings],
        $project,
    );
    [$bigExit, $bigStdout, $bigStderr] = warpBinRun(
        ['shard', '1/2000000000', 'tests', '--timings-dir='.$timings],
        $project,
    );

    expect($hugeExit)->toBe(2)
        ->and($hugeStdout)->toBe('')
        ->and($hugeStderr)->toContain('[warp] shard total out of range')
        ->and($hugeStderr)->toContain('10000')
        ->and($hugeStderr)->not->toContain('Stack trace')
        ->and($bigExit)->toBe(2)
        ->and($bigStdout)->toBe('')
        ->and($bigStderr)->toContain('[warp] shard total out of range')
        ->and($bigStderr)->not->toContain('Fatal error');
});

it('degrades to count-balanced sharding with a warning when timings.json is undecodable', function () {
    $project = createWarpFixtureProject($this->dir);
    $timings = $project.'/timings';
    Dirs::ensure($timings);
    // A CI cache artifact truncated mid-file: valid prefix, undecodable as a whole.
    file_put_contents($timings.'/timings.json', '{"version":2,"tests":{"ATest::one":{"file":"tests/ATest.p');

    [$exit, $stdout, $stderr] = warpBinRun(
        ['shard', '1/2', 'tests', '--timings-dir='.$timings],
        $project,
    );

    expect($exit)->toBe(0)
        ->and($stdout)->not->toBe('')
        ->and($stderr)->toContain('[warp] cannot decode timings')
        ->and($stderr)->not->toContain('Stack trace');
});

it('merges a non-finite ms pending batch to exit 0, cleans it up, and lets later merges succeed', function () {
    $project = createWarpFixtureProject($this->dir);
    $timings = $project.'/timings';
    Dirs::ensure($timings.'/pending');
    file_put_contents($timings.'/pending/100-1-aabbccdd.json', json_encode([
        'complete' => true,
        'tests' => ['poison' => ['file' => 'tests/ATest.php', 'ms' => '1e999']],
    ], JSON_THROW_ON_ERROR));

    [$firstExit, , $firstStderr] = warpBinRun(['merge', '--timings-dir='.$timings], $project);
    [$secondExit, $secondStdout] = warpBinRun(['merge', '--timings-dir='.$timings], $project);

    expect($firstExit)->toBe(0)
        ->and(glob($timings.'/pending/*.json'))->toBe([])
        ->and($firstStderr)->not->toContain('Stack trace')
        ->and($secondExit)->toBe(0)
        ->and($secondStdout)->toContain('nothing to merge');
});

it('prints usage and exits 2 without a command', function () {
    [$exit, $stdout, $stderr] = warpBinRun([]);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('usage:');
});

it('exits 3 for an empty shard', function () {
    [$exit, $stdout] = warpBinRun(['shard', '60/60', 'tests/Unit', '--timings-dir='.$this->dir]);

    expect($exit)->toBe(3)
        ->and($stdout)->toBe('');
});

it('loads pending timings from a read-only artifact without writing while sharding and reporting timings', function () {
    $project = createWarpFixtureProject($this->dir);
    $timings = $project.'/timings';
    writeTimingArtifactWithPendingOverlay($timings);
    makeTimingArtifactReadOnly($timings);

    clearstatcache();
    $beforeSnapshot = timingArtifactSnapshot($timings);
    $beforeDirMtime = filemtime($timings);
    $beforePendingMtime = filemtime($timings.'/pending');

    [$shardExit, $shardStdout, $shardStderr] = warpBinRun(
        ['shard', '1/2', 'tests', '--timings-dir='.$timings],
        $project,
    );
    [$timingsExit, $timingsStdout, $timingsStderr] = warpBinRun(
        ['timings', '--timings-dir='.$timings],
        $project,
    );

    clearstatcache();

    expect($shardExit)->toBe(0)
        ->and($shardStdout)->toBe("tests/ATest.php\n")
        ->and($shardStderr)->toBe('')
        ->and($timingsExit)->toBe(0)
        ->and($timingsStdout)->toContain('2 tests across 2 files - 101.0ms recorded')
        ->and($timingsStderr)->toBe('')
        ->and(timingArtifactSnapshot($timings))->toBe($beforeSnapshot)
        ->and(filemtime($timings))->toBe($beforeDirMtime)
        ->and(filemtime($timings.'/pending'))->toBe($beforePendingMtime);
});

it('exposes shard exit codes 0, 3, and 2 through the real binary', function () {
    $project = createWarpFixtureProject($this->dir, ['OnlyTest.php']);

    [$successExit, $successStdout, $successStderr] = warpBinRun(
        ['shard', '1/1', 'tests', '--timings-dir='.$project.'/timings'],
        $project,
    );
    [$emptyExit, $emptyStdout, $emptyStderr] = warpBinRun(
        ['shard', '2/2', 'tests', '--timings-dir='.$project.'/timings'],
        $project,
    );
    [$errorExit, $errorStdout, $errorStderr] = warpBinRun(
        ['shard', '1/1', 'missing-tests', '--timings-dir='.$project.'/timings'],
        $project,
    );

    expect($successExit)->toBe(0)
        ->and($successStdout)->toBe("tests/OnlyTest.php\n")
        ->and($successStderr)->toContain('no recorded timings')
        ->and($emptyExit)->toBe(3)
        ->and($emptyStdout)->toBe('')
        ->and($emptyStderr)->toContain('is empty')
        ->and($errorExit)->toBe(2)
        ->and($errorStdout)->toBe('')
        ->and($errorStderr)->toContain('[warp] no such test path');
});

it('exits 2 when the real binary discovers zero test files', function () {
    $project = createWarpFixtureProject($this->dir, []);
    file_put_contents($project.'/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Empty">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);

    [$exit, $stdout, $stderr] = warpBinRun(
        ['shard', '1/2', '--timings-dir='.$project.'/timings'],
        $project,
    );

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toBe("[warp] no test files discovered - nothing to shard\n");
});

it('keeps the sh -e shard guard fatal when discovery finds zero test files', function () {
    $project = createWarpFixtureProject($this->dir, []);
    $root = dirname(__DIR__, 3);
    $php = escapeshellarg(PHP_BINARY);
    $warp = escapeshellarg($root.'/bin/warp');
    $timings = escapeshellarg($project.'/timings');
    file_put_contents($project.'/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Empty">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);

    $guard = sprintf(
        <<<'SH'
set +e
FILES=$(%s %s shard 1/2 --timings-dir=%s)
rc=$?
set -e
if [ "$rc" -eq 3 ]; then
    echo "skip"
    exit 0
fi
if [ "$rc" -ne 0 ]; then
    exit "$rc"
fi
printf 'run:%%s\n' "$FILES"
SH,
        $php,
        $warp,
        $timings,
    );

    [$exit, $stdout, $stderr] = shellRun($guard, $project);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toBe("[warp] no test files discovered - nothing to shard\n");
});

it('keeps the corrected sh -e shard guard tolerant of empty shards but fatal on errors', function () {
    $project = createWarpFixtureProject($this->dir, ['OnlyTest.php']);
    $root = dirname(__DIR__, 3);
    $php = escapeshellarg(PHP_BINARY);
    $warp = escapeshellarg($root.'/bin/warp');
    $timings = escapeshellarg($project.'/timings');

    $guard = static function (string $spec, string $path) use ($php, $warp, $timings): string {
        return sprintf(
            <<<'SH'
set +e
FILES=$(%s %s shard %s %s --timings-dir=%s)
rc=$?
set -e
if [ "$rc" -eq 3 ]; then
    echo "skip"
    exit 0
fi
if [ "$rc" -ne 0 ]; then
    exit "$rc"
fi
printf 'run:%%s\n' "$FILES"
SH,
            $php,
            $warp,
            escapeshellarg($spec),
            escapeshellarg($path),
            $timings,
        );
    };

    [$runExit, $runStdout, $runStderr] = shellRun($guard('1/1', 'tests'), $project);
    [$skipExit, $skipStdout, $skipStderr] = shellRun($guard('2/2', 'tests'), $project);
    [$errorExit, $errorStdout, $errorStderr] = shellRun($guard('1/1', 'missing-tests'), $project);

    expect($runExit)->toBe(0)
        ->and($runStdout)->toBe("run:tests/OnlyTest.php\n")
        ->and($runStderr)->toContain('no recorded timings')
        ->and($skipExit)->toBe(0)
        ->and($skipStdout)->toBe("skip\n")
        ->and($skipStderr)->toContain('is empty')
        ->and($errorExit)->toBe(2)
        ->and($errorStdout)->toBe('')
        ->and($errorStderr)->toContain('[warp] no such test path');
});

it('merges pending timings and keeps subsequent read-only shard output byte-identical to the overlay plan', function () {
    $project = createWarpFixtureProject($this->dir);
    $timings = $project.'/timings';

    (new TimingStore($timings))->writePending([
        'ATest::fresh' => ['file' => 'tests/ATest.php', 'ms' => 100.0],
        'BTest::fresh' => ['file' => 'tests/BTest.php', 'ms' => 1.0],
    ]);

    [$preExit, $preStdout, $preStderr] = warpBinRun(
        ['shard', '1/2', 'tests', '--timings-dir='.$timings],
        $project,
    );
    [$mergeExit, $mergeStdout, $mergeStderr] = warpBinRun(
        ['merge', '--timings-dir='.$timings],
        $project,
    );

    expect($mergeExit)->toBe(0)
        ->and($mergeStdout)->toContain('merged 1 pending timing batch')
        ->and($mergeStderr)->toBe('')
        ->and(glob($timings.'/pending/*.json'))->toBe([]);

    makeTimingArtifactReadOnly($timings);

    [$postExit, $postStdout, $postStderr] = warpBinRun(
        ['shard', '1/2', 'tests', '--timings-dir='.$timings],
        $project,
    );

    expect($preExit)->toBe(0)
        ->and($preStdout)->toBe("tests/ATest.php\n")
        ->and($preStderr)->toBe('')
        ->and($postExit)->toBe($preExit)
        ->and($postStdout)->toBe($preStdout)
        ->and($postStderr)->toBe($preStderr);
});

it('bench shard spread resolves root-relative timings for absolute suite paths', function () {
    $project = createWarpFixtureProject($this->dir);
    $timings = $project.'/.warp/timings';
    writeTimingArtifactForFiles($timings, [
        'tests/ATest.php' => 100.0,
        'tests/BTest.php' => 1.0,
        'tests/CTest.php' => 1.0,
    ]);

    [$relativeExit, $relativeStdout, $relativeStderr] = benchShardSpreadRun(
        [$timings, '2', 'tests'],
        $project,
    );
    [$absoluteExit, $absoluteStdout, $absoluteStderr] = benchShardSpreadRun(
        [$timings, '2', $project.'/tests'],
        $project,
    );

    expect($relativeExit)->toBe(0)
        ->and($relativeStderr)->toBe('')
        ->and($relativeStdout)->toContain('3 files, 102.0ms recorded, 2 shards')
        ->and($relativeStdout)->toContain('101.0               100.0')
        ->and($absoluteExit)->toBe(0)
        ->and($absoluteStderr)->toBe('')
        ->and($absoluteStdout)->toBe($relativeStdout);
});

it('bench shard spread warns when recorded timings match no discovered file', function () {
    $project = createWarpFixtureProject($this->dir);
    $timings = $project.'/.warp/timings';
    writeTimingArtifactForFiles($timings, [
        'other/ATest.php' => 100.0,
    ]);

    [$exit, $stdout, $stderr] = benchShardSpreadRun(
        [$timings, '2', 'tests'],
        $project,
    );

    expect($exit)->toBe(0)
        ->and($stdout)->toContain('3 files, 3.0ms recorded, 2 shards')
        ->and($stderr)->toContain('recorded timings match no discovered file');
});

it('bench shard spread does not let stale timings mask a pest crash', function () {
    $project = createWarpFixtureProject($this->dir, ['ATest.php', 'BTest.php']);
    $root = dirname(__DIR__, 3);
    $timings = $project.'/.warp/timings';
    writeTimingArtifactForFiles($timings, [
        'tests/ATest.php' => 100.0,
        'tests/BTest.php' => 1.0,
    ]);
    $beforeSnapshot = timingArtifactSnapshot($timings);

    writeFakePestBinary($project, <<<'SH'
exit 17
SH);

    $process = proc_open(
        ['bash', $root.'/bench/shard-spread.sh', $project, '2', 'tests'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $project,
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(17)
        ->and((string) $stdout)->not->toContain('files, 101.0ms recorded')
        ->and((string) $stderr)->not->toContain('continuing because timing artifacts were recorded')
        ->and(timingArtifactSnapshot($timings))->toBe($beforeSnapshot);
});

it('prints a [warp] diagnostic and exits 2 when no autoload candidate exists, no PHP fatal', function () {
    $root = dirname(__DIR__, 3);
    $isolated = $this->dir.'/isolated';
    Dirs::ensure($isolated.'/bin');
    copy($root.'/bin/warp', $isolated.'/bin/warp');
    chmod($isolated.'/bin/warp', 0755);

    // Sanity: neither autoload candidate the copied bin/warp probes for exists
    // anywhere in this isolated tree's ancestry.
    expect(file_exists($isolated.'/vendor/autoload.php'))->toBeFalse()
        ->and(file_exists(dirname($isolated, 2).'/autoload.php'))->toBeFalse();

    $process = proc_open(
        ['php', $isolated.'/bin/warp', 'shard', '1/2'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $isolated,
    );

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(2)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('[warp]')
        ->and($stderr)->toContain('composer install')
        ->and($stderr)->not->toContain('Fatal error')
        ->and($stderr)->not->toContain('Stack trace');
});

it('bench shard spread continues after pest failure when the fresh run recorded timings', function () {
    $project = createWarpFixtureProject($this->dir, ['ATest.php', 'BTest.php']);
    $root = dirname(__DIR__, 3);
    $staleTimings = $project.'/.warp/timings';
    writeTimingArtifactForFiles($staleTimings, [
        'tests/ATest.php' => 1.0,
    ]);
    $staleArtifact = (string) file_get_contents($staleTimings.'/timings.json');

    writeFakePestBinary($project, <<<'SH'
mkdir -p "$WARP_TIMINGS_DIR"
cat > "$WARP_TIMINGS_DIR/timings.json" <<'JSON'
{"version":3,"tests":{"ATest::test":{"file":"tests/ATest.php","ms":100},"BTest::test":{"file":"tests/BTest.php","ms":1}}}
JSON
exit 17
SH);

    $process = proc_open(
        ['bash', $root.'/bench/shard-spread.sh', $project, '2', 'tests'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $project,
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0)
        ->and((string) $stdout)->toContain('2 files, 101.0ms recorded, 2 shards')
        ->and((string) $stderr)->toContain('continuing because timing artifacts were recorded')
        ->and((string) file_get_contents($staleTimings.'/timings.json'))->toBe($staleArtifact)
        ->and(glob($staleTimings.'/run-*/timings.json'))->toHaveCount(1);
});
