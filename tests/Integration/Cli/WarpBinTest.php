<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Shard\TestFileFinder;
use RawPHP\Warp\Timing\TimingStore;

/** @return array{0: int, 1: string, 2: string} [exit, stdout, stderr] */
function warpBinRun(array $args): array
{
    $root = dirname(__DIR__, 3);

    $process = proc_open(
        ['php', $root.'/bin/warp', ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    return [proc_close($process), (string) $stdout, (string) $stderr];
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/warp-bin-'.bin2hex(random_bytes(4));
});

afterEach(function () {
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
