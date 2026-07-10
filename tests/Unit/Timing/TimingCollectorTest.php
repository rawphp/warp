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

it('marks a file complete when every enumerated test terminated', function () {
    $collector = new TimingCollector;

    $collector->enumerated('F::one', 'tests/FTest.php');
    $collector->enumerated('F::two', 'tests/FTest.php');

    $collector->started('F::one', 1.0);
    $collector->finished('F::one', 'tests/FTest.php', 1.5);
    $collector->started('F::two', 2.0);
    $collector->finished('F::two', 'tests/FTest.php', 2.5);

    expect($collector->completeFiles())->toBe(['tests/FTest.php' => true]);
});

it('marks a file incomplete when an enumerated test never terminates', function () {
    $collector = new TimingCollector;

    $collector->enumerated('F::one', 'tests/FTest.php');
    $collector->enumerated('F::two', 'tests/FTest.php');

    // Only the first test runs to completion; the second is enumerated but the
    // process dies mid-run (never Finished/Skipped/Errored/MarkedIncomplete).
    $collector->started('F::one', 1.0);
    $collector->finished('F::one', 'tests/FTest.php', 1.5);
    $collector->started('F::two', 2.0);

    expect($collector->completeFiles())->toBe(['tests/FTest.php' => false]);
});

it('closes a setUp skip through the terminated hook so its file stays complete (finding 16)', function () {
    $collector = new TimingCollector;

    $collector->enumerated('F::passes', 'tests/FTest.php');
    $collector->enumerated('F::skipsInSetUp', 'tests/FTest.php');

    $collector->started('F::passes', 1.0);
    $collector->finished('F::passes', 'tests/FTest.php', 1.5);

    // A setUp/requirement skip emits PreparationStarted but never Finished; the
    // Skipped subscriber terminates its accounting entry.
    $collector->started('F::skipsInSetUp', 2.0);
    $collector->terminated('F::skipsInSetUp', 'tests/FTest.php');

    expect($collector->completeFiles())->toBe(['tests/FTest.php' => true]);
});

it('closes a phpt-style non-method test through the terminated hook (finding 5)', function () {
    $collector = new TimingCollector;

    $collector->enumerated('P::phpt', 'tests/example.phpt');

    // A .phpt test emits PreparationStarted and Finished but is not a TestMethod;
    // completeness accounting still closes it via the terminated hook.
    $collector->started('P::phpt', 1.0);
    $collector->terminated('P::phpt', 'tests/example.phpt');

    expect($collector->completeFiles())->toBe(['tests/example.phpt' => true]);
});

it('a terminal event enumerates a test that never emitted a start', function () {
    $collector = new TimingCollector;

    // Requirement skips can be reported terminal with no prior start; the file is
    // still complete because the only enumerated test terminated.
    $collector->terminated('F::requirementSkip', 'tests/FTest.php');

    expect($collector->completeFiles())->toBe(['tests/FTest.php' => true]);
});

it('never marks a file complete when a worker saw only a slice of it (finding 14)', function () {
    // Simulates a paratest --functional worker: the full file is enumerated from
    // TestSuite\Loaded, but the injected filter runs only two of the four methods.
    $collector = new TimingCollector;

    foreach (['F::a', 'F::b', 'F::c', 'F::d'] as $id) {
        $collector->enumerated($id, 'tests/FTest.php');
    }

    $collector->started('F::a', 1.0);
    $collector->finished('F::a', 'tests/FTest.php', 1.1);
    $collector->started('F::b', 2.0);
    $collector->finished('F::b', 'tests/FTest.php', 2.1);

    expect($collector->completeFiles())->toBe(['tests/FTest.php' => false]);
});

it('records a per-file completeness map for multiple files independently', function () {
    $collector = new TimingCollector;

    $collector->enumerated('A::one', 'tests/ATest.php');
    $collector->enumerated('B::one', 'tests/BTest.php');
    $collector->enumerated('B::two', 'tests/BTest.php');

    $collector->started('A::one', 1.0);
    $collector->finished('A::one', 'tests/ATest.php', 1.5);
    $collector->started('B::one', 2.0);
    $collector->finished('B::one', 'tests/BTest.php', 2.5);
    // B::two enumerated but never terminated -> BTest incomplete.

    expect($collector->completeFiles())->toBe([
        'tests/ATest.php' => true,
        'tests/BTest.php' => false,
    ]);
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

it('leaves hasFlushed false after a failed write so a retry can recover (REQ-100)', function () {
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
            $collector->flush($store);
        } catch (RuntimeException $e) {
            $writeFailed = true;

            expect($e->getMessage())->toContain('[warp] cannot create directory');
        }

        // The write threw before the flag was set: hasFlushed() must stay false so
        // the shutdown backstop does not skip a retry (the original REQ-100 bug had
        // the flag set true here, permanently losing the run's timings).
        expect($writeFailed)->toBeTrue()
            ->and($collector->hasFlushed())->toBeFalse();

        unlink($dir.'/pending');

        // Backstop retry with the same collector and store: the transient failure
        // is gone, so this call must actually write the batch.
        $collector->flush($store);

        expect($collector->hasFlushed())->toBeTrue()
            ->and(glob($dir.'/pending/*.json'))->toHaveCount(1);
    } finally {
        Dirs::delete($dir);
    }
});

it('flush stamps the per-file completeness map into the pending batch', function () {
    $dir = sys_get_temp_dir().'/warp-collector-'.bin2hex(random_bytes(4));
    $store = new TimingStore($dir);
    $collector = new TimingCollector;

    $collector->enumerated('t::one', 'tests/OneTest.php');
    $collector->enumerated('t::two', 'tests/OneTest.php');
    $collector->started('t::one', 1.0);
    $collector->finished('t::one', 'tests/OneTest.php', 1.5);
    // t::two enumerated but never terminated -> file incomplete.

    $collector->flush($store);

    $path = glob($dir.'/pending/*.json')[0] ?? null;
    $payload = json_decode((string) file_get_contents((string) $path), true);

    expect($payload)->toEqual([
        'complete' => ['tests/OneTest.php' => false],
        'root' => null,
        'tests' => ['t::one' => ['file' => 'tests/OneTest.php', 'ms' => 500.0]],
    ]);

    Dirs::delete($dir);
});

it('flush marks a fully-terminated file complete in the pending batch', function () {
    $dir = sys_get_temp_dir().'/warp-collector-'.bin2hex(random_bytes(4));
    $store = new TimingStore($dir);
    $collector = new TimingCollector;

    $collector->enumerated('t::one', 'tests/OneTest.php');
    $collector->started('t::one', 1.0);
    $collector->finished('t::one', 'tests/OneTest.php', 1.5);

    $collector->flush($store);

    $path = glob($dir.'/pending/*.json')[0] ?? null;
    $payload = json_decode((string) file_get_contents((string) $path), true);

    expect($payload['complete'] ?? null)->toBe(['tests/OneTest.php' => true]);

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
        $flush->invoke(null, $collector, $store);
        $flush->invoke(null, $collector, $store);
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
        $flush->invoke(null, $collector, $store);
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
