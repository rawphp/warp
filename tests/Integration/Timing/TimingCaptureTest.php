<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
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

    // load() consumed the pending batch into the merged file.
    expect(glob($dir.'/pending/*.json'))->toBe([]);

    Dirs::delete($dir);
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
