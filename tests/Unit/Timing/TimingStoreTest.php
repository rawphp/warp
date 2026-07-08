<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Timing\TimingStore;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/warp-timings-'.bin2hex(random_bytes(4));
    $this->store = new TimingStore($this->dir);
});

afterEach(function () {
    putenv('WARP_TIMINGS_DIR');
    Dirs::delete($this->dir);
});

it('loads empty when nothing was ever recorded, without creating directories', function () {
    expect($this->store->load())->toBe([])
        ->and(is_dir($this->dir))->toBeFalse();
});

it('writePending is a no-op for an empty batch', function () {
    $this->store->writePending([]);

    expect(is_dir($this->dir))->toBeFalse();
});

it('merges pending batches into the store and clears them', function () {
    $this->store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);

    expect(glob($this->dir.'/pending/*.json'))->toHaveCount(1);

    $tests = $this->store->load();

    expect($tests)->toBe(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]])
        ->and(glob($this->dir.'/pending/*.json'))->toBe([]);
});

it('writes pending batches atomically and leaves no temporary file behind', function () {
    $this->store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);

    $files = array_values(array_diff(scandir($this->dir.'/pending') ?: [], ['.', '..']));

    expect($files)->toHaveCount(1)
        ->and($files[0])->toEndWith('.json')
        ->and($files[0])->not->toEndWith('.tmp')
        ->and(json_decode((string) file_get_contents($this->dir.'/pending/'.$files[0]), true))->toBe([
            't1' => ['file' => 'tests/ATest.php', 'ms' => 10.5],
        ]);
});

it('a rerun of a file supersedes all of that file\'s previous entries', function () {
    $this->store->writePending([
        't1' => ['file' => 'tests/ATest.php', 'ms' => 10.5],
        't2' => ['file' => 'tests/ATest.php', 'ms' => 20.5],
        't3' => ['file' => 'tests/BTest.php', 'ms' => 30.5],
    ]);
    $this->store->mergePending();

    // t2 was renamed/deleted since: the fresh run of ATest.php only has t1.
    $this->store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 11.5]]);

    $tests = $this->store->load();

    expect($tests)->toHaveKeys(['t1', 't3'])
        ->and($tests)->not->toHaveKey('t2')
        ->and($tests['t1']['ms'])->toBe(11.5)
        ->and($tests['t3']['ms'])->toBe(30.5);
});

it('leaves corrupt pending files in place and warns while merging valid siblings', function () {
    Dirs::ensure($this->dir.'/pending');
    $badPath = $this->dir.'/pending/0-bad.json';
    file_put_contents($badPath, 'not json');
    file_put_contents($this->dir.'/pending/1-good.json', json_encode([
        't1' => ['file' => 'tests/ATest.php', 'ms' => 10.5],
    ]));

    $script = $this->dir.'/merge.php';
    file_put_contents($script, <<<'PHP'
<?php

require getcwd().'/vendor/autoload.php';

(new RawPHP\Warp\Timing\TimingStore($argv[1]))->load();
PHP);

    $process = proc_open([PHP_BINARY, $script, $this->dir], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, getcwd());

    expect($process)->not->toBeFalse();

    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0);

    $merged = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

    expect($merged['tests'] ?? [])->toHaveKey('t1')
        ->and(is_file($badPath))->toBeTrue()
        ->and($stderr)->toContain('0-bad.json');
});

it('discovers pending batches when the store path contains glob metacharacters', function () {
    Dirs::delete($this->dir);

    $this->dir = sys_get_temp_dir().'/warp-timings-base[1]-star*-question?/timings';
    $this->store = new TimingStore($this->dir);

    $this->store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);

    expect($this->store->load())->toBe(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);
});

it('drops malformed entries from a pending batch', function () {
    Dirs::ensure($this->dir.'/pending');
    file_put_contents($this->dir.'/pending/0-mixed.json', json_encode([
        'good' => ['file' => 'tests/ATest.php', 'ms' => 5.5],
        'no-file' => ['ms' => 5.5],
        'bad-ms' => ['file' => 'tests/BTest.php', 'ms' => 'slow'],
    ]));

    expect(array_keys($this->store->load()))->toBe(['good']);
});

it('treats a merged file with an unknown version as empty', function () {
    Dirs::ensure($this->dir);
    file_put_contents($this->dir.'/timings.json', json_encode([
        'version' => 99,
        'tests' => ['t9' => ['file' => 'tests/XTest.php', 'ms' => 1.5]],
    ]));

    expect($this->store->load())->toBe([]);
});

it('aggregates per-file totals sorted by path', function () {
    $totals = TimingStore::aggregate([
        't1' => ['file' => 'tests/BTest.php', 'ms' => 1.5],
        't2' => ['file' => 'tests/ATest.php', 'ms' => 2.5],
        't3' => ['file' => 'tests/BTest.php', 'ms' => 2.0],
    ]);

    expect($totals)->toBe(['tests/ATest.php' => 2.5, 'tests/BTest.php' => 3.5]);
});

it('fileTotals merges then aggregates', function () {
    $this->store->writePending([
        't1' => ['file' => 'tests/ATest.php', 'ms' => 1.5],
        't2' => ['file' => 'tests/ATest.php', 'ms' => 2.0],
    ]);

    expect($this->store->fileTotals())->toBe(['tests/ATest.php' => 3.5]);
});

it('fromEnv honours WARP_TIMINGS_DIR', function () {
    putenv('WARP_TIMINGS_DIR='.$this->dir);

    TimingStore::fromEnv()->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 1.5]]);

    expect(glob($this->dir.'/pending/*.json'))->toHaveCount(1);
});
