<?php

declare(strict_types=1);

use PHPUnit\Event\Code\Phpt;
use RawPHP\Warp\Timing\TimingExtension;

it('no longer exposes the deleted process-level completeness machinery (finding 4/15)', function () {
    // The stop-on sniffer and the process-wide selected/finished counters are gone;
    // completeness is decided per file from terminal events, not inferred state.
    expect(method_exists(TimingExtension::class, 'hasStopOnConfiguration'))->toBeFalse()
        ->and(method_exists(TimingExtension::class, 'hasIncompleteSelectionConfiguration'))->toBeFalse();
});

it('no longer exposes the deleted fatal-error shutdown heuristic (finding 5)', function () {
    expect(method_exists(TimingExtension::class, 'shutdownHadFatalError'))->toBeFalse()
        ->and(method_exists(TimingExtension::class, 'shutdownBackstopComplete'))->toBeFalse();
});

it('has no lingering references to the deleted machinery or counters in the source', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Timing/TimingExtension.php');

    foreach ([
        'hasStopOnConfiguration',
        'shutdownHadFatalError',
        'shutdownBackstopComplete',
        'hasIncompleteSelectionConfiguration',
        'selectedTests',
        'finishedTests',
        'error_get_last',
        'stopOnDefect',
    ] as $needle) {
        expect($source)->not->toContain($needle);
    }
});

it('subscribes to every terminal outcome so no enumerated test can leak in-flight', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Timing/TimingExtension.php');

    // TestSuite\Loaded enumerates the full (pre-filter) suite; the four terminal
    // events close each enumerated entry even when Test\Finished never fires.
    foreach ([
        'LoadedSubscriber',
        'FinishedSubscriber',
        'SkippedSubscriber',
        'ErroredSubscriber',
        'MarkedIncompleteSubscriber',
    ] as $subscriber) {
        expect($source)->toContain($subscriber);
    }
});

it('resolves a non-method (.phpt) event to its canonical root-relative file key', function () {
    $root = dirname(__DIR__, 3);
    $phpt = __DIR__.'/warp-filefor-'.bin2hex(random_bytes(4)).'.phpt';
    file_put_contents($phpt, "--TEST--\nx\n--FILE--\n<?php\n--EXPECT--\n");

    try {
        $test = new Phpt($phpt);
        $expected = 'tests/Unit/Timing/'.basename($phpt);

        // A .phpt is not a TestMethod, so completeness accounting canonicalizes
        // its reported file directly instead of via the Pest class resolver.
        expect($test->isTestMethod())->toBeFalse()
            ->and(TimingExtension::fileFor($test, $root))->toBe($expected);
    } finally {
        @unlink($phpt);
    }
});
