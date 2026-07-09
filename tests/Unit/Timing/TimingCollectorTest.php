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

it('reports whether a test is currently in flight', function () {
    $collector = new TimingCollector;

    expect($collector->hasInFlight())->toBeFalse();

    $collector->started('t::one', 1.0);

    expect($collector->hasInFlight())->toBeTrue();

    $collector->finished('t::one', 'tests/OneTest.php', 1.5);

    expect($collector->hasInFlight())->toBeFalse();
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

it('counts tests whose file could not be attributed', function () {
    $collector = new TimingCollector;

    $collector->started('t::one', 1.0);
    $collector->started('t::two', 1.0);
    $collector->finished('t::one', null, 2.0);
    $collector->finished('t::two', null, 3.0);
    $collector->finished('t::ghost', null, 4.0);

    expect($collector->all())->toBe([])
        ->and($collector->unattributedCount())->toBe(2);
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

it('does not mark flushed after a failed pending write so shutdown can retry', function () {
    $dir = sys_get_temp_dir().'/warp-collector-'.bin2hex(random_bytes(8));
    $store = new TimingStore($dir);
    $collector = new TimingCollector;

    try {
        $collector->started('t::one', 1.0);
        $collector->finished('t::one', 'tests/OneTest.php', 1.5);

        Dirs::ensure($dir);
        expect(file_put_contents($dir.'/pending', 'not-a-directory'))->not->toBeFalse();

        $writeFailed = false;

        try {
            $collector->flush($store, complete: true);
        } catch (RuntimeException $e) {
            $writeFailed = true;

            expect($e->getMessage())->toContain('[warp] cannot create directory');
        }

        expect($writeFailed)->toBeTrue()
            ->and($collector->hasFlushed())->toBeFalse();

        unlink($dir.'/pending');

        $collector->flush($store, complete: false);

        $path = glob($dir.'/pending/*.json')[0] ?? null;
        $payload = json_decode((string) file_get_contents((string) $path), true);

        expect($collector->hasFlushed())->toBeTrue()
            ->and(glob($dir.'/pending/*.json'))->toHaveCount(1)
            ->and($payload)->toEqual([
                'complete' => false,
                'tests' => ['t::one' => ['file' => 'tests/OneTest.php', 'ms' => 500.0]],
            ]);

        $collector->flush($store, complete: true);

        expect(glob($dir.'/pending/*.json'))->toHaveCount(1);
    } finally {
        Dirs::delete($dir);
    }
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

it('emits one stderr warning when unattributed tests are flushed', function () {
    $result = runTimingExtensionFlushScript(<<<'PHP'
        $collector = new TimingCollector;
        $collector->started('t::one', 1.0);
        $collector->started('t::two', 1.0);
        $collector->finished('t::one', null, 2.0);
        $collector->finished('t::two', null, 3.0);
        $store = new TimingStore($dir);
        $flush = new ReflectionMethod(TimingExtension::class, 'flush');
        $flush->setAccessible(true);
        $flush->invoke(null, $collector, $store, true);
        $flush->invoke(null, $collector, $store, true);
        PHP);

    expect($result['exit'])->toBe(0)
        ->and($result['stderr'])->toBe("[warp] 2 test(s) could not be attributed to a file; their timings were not recorded\n");
});

it('does not warn when all flushed tests were attributed', function () {
    $result = runTimingExtensionFlushScript(<<<'PHP'
        $collector = new TimingCollector;
        $collector->started('t::one', 1.0);
        $collector->finished('t::one', 'tests/OneTest.php', 2.0);
        $store = new TimingStore($dir);
        $flush = new ReflectionMethod(TimingExtension::class, 'flush');
        $flush->setAccessible(true);
        $flush->invoke(null, $collector, $store, true);
        PHP);

    expect($result['exit'])->toBe(0)
        ->and($result['stderr'])->toBe('');
});

/**
 * @return array{exit: int, stdout: string, stderr: string}
 */
function runTimingExtensionFlushScript(string $body): array
{
    $script = <<<'PHP'
        <?php

        require 'vendor/autoload.php';

        use RawPHP\Warp\Db\Dirs;
        use RawPHP\Warp\Timing\TimingCollector;
        use RawPHP\Warp\Timing\TimingExtension;
        use RawPHP\Warp\Timing\TimingStore;

        $dir = sys_get_temp_dir().'/warp-extension-flush-'.bin2hex(random_bytes(4));

        try {
        PHP
        .$body.
        <<<'PHP'
        } finally {
            Dirs::delete($dir);
        }
        PHP;

    $process = proc_open([PHP_BINARY], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, getcwd());

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start PHP subprocess.');
    }

    fwrite($pipes[0], $script);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return [
        'exit' => proc_close($process),
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}
