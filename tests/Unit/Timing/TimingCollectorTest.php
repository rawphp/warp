<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Timing\TimingCollector;
use RawPHP\Warp\Timing\TimingStore;

it('records the prepare->finish delta in milliseconds', function () {
    $collector = new TimingCollector;

    $collector->started('t::one', 100.0);
    $collector->finished('t::one', 'tests/OneTest.php', 100.0625);

    expect($collector->all())->toBe(['t::one' => ['file' => 'tests/OneTest.php', 'ms' => 62.5]]);
});

it('tracks interleaved tests independently', function () {
    $collector = new TimingCollector;

    $collector->started('t::a', 10.0);
    $collector->started('t::b', 10.5);
    $collector->finished('t::a', 'tests/ATest.php', 10.25);
    $collector->finished('t::b', 'tests/BTest.php', 11.0);

    expect($collector->all())->toBe([
        't::a' => ['file' => 'tests/ATest.php', 'ms' => 250.0],
        't::b' => ['file' => 'tests/BTest.php', 'ms' => 500.0],
    ]);
});

it('ignores a finish without a matching start', function () {
    $collector = new TimingCollector;

    $collector->finished('t::ghost', 'tests/GhostTest.php', 5.0);

    expect($collector->all())->toBe([]);
});

it('ignores tests whose file could not be attributed', function () {
    $collector = new TimingCollector;

    $collector->started('t::anon', 1.0);
    $collector->finished('t::anon', null, 2.0);

    expect($collector->all())->toBe([]);
});

it('flush writes a single pending batch and only once', function () {
    $dir = sys_get_temp_dir().'/warp-collector-'.bin2hex(random_bytes(4));
    $store = new TimingStore($dir);
    $collector = new TimingCollector;

    $collector->started('t::one', 1.0);
    $collector->finished('t::one', 'tests/OneTest.php', 1.5);

    $collector->flush($store);
    $collector->flush($store);

    expect(glob($dir.'/pending/*.json'))->toHaveCount(1);

    Dirs::delete($dir);
});

it('flush marks normally completed batches complete by default', function () {
    $dir = sys_get_temp_dir().'/warp-collector-'.bin2hex(random_bytes(4));
    $store = new TimingStore($dir);
    $collector = new TimingCollector;

    $collector->started('t::one', 1.0);
    $collector->finished('t::one', 'tests/OneTest.php', 1.5);

    $collector->flush($store);

    $path = glob($dir.'/pending/*.json')[0] ?? null;
    $payload = json_decode((string) file_get_contents((string) $path), true);

    expect($payload)->toEqual([
        'complete' => true,
        'tests' => ['t::one' => ['file' => 'tests/OneTest.php', 'ms' => 500.0]],
    ]);

    Dirs::delete($dir);
});

it('flush can mark shutdown backstop batches incomplete', function () {
    $dir = sys_get_temp_dir().'/warp-collector-'.bin2hex(random_bytes(4));
    $store = new TimingStore($dir);
    $collector = new TimingCollector;

    $collector->started('t::one', 1.0);
    $collector->finished('t::one', 'tests/OneTest.php', 1.5);

    $collector->flush($store, complete: false);

    $path = glob($dir.'/pending/*.json')[0] ?? null;
    $payload = json_decode((string) file_get_contents((string) $path), true);

    expect($payload['complete'] ?? null)->toBeFalse()
        ->and($payload['tests'] ?? [])->toEqual([
            't::one' => ['file' => 'tests/OneTest.php', 'ms' => 500.0],
        ]);

    Dirs::delete($dir);
});

it('flush with nothing recorded writes nothing', function () {
    $dir = sys_get_temp_dir().'/warp-collector-'.bin2hex(random_bytes(4));

    (new TimingCollector)->flush(new TimingStore($dir));

    expect(is_dir($dir))->toBeFalse();
});
