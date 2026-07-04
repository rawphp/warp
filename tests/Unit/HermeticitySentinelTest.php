<?php

declare(strict_types=1);

use Warp\ResetManifest;
use Warp\Sentinel\HermeticitySentinel;
use Warp\WarmApplicationFactory;

it('reports clean when nothing changed', function () {
    $app = $this->createClassicApplication();
    $sentinel = HermeticitySentinel::capture($app);

    expect($sentinel->check($app)->clean())->toBeTrue();
});

it('detects a leaked env var, names it, and restores pristine state', function () {
    $app = $this->createClassicApplication();
    $sentinel = HermeticitySentinel::capture($app);

    putenv('LEAKED_TEST_VAR=oops');
    $_ENV['LEAKED_TEST_VAR'] = 'oops';

    $report = $sentinel->check($app);

    expect($report->clean())->toBeFalse()
        ->and($report->describe())->toContain('LEAKED_TEST_VAR')
        ->and(getenv('LEAKED_TEST_VAR'))->toBeFalse()
        ->and($sentinel->check($app)->clean())->toBeTrue();
});

it('detects a removed env var and restores it', function () {
    putenv('WARP_TEST_PRESET=keep-me');
    $app = $this->createClassicApplication();
    // WARP_-prefixed vars are ignored as orchestration state, so use a plain one.
    putenv('PLAIN_PRESET=keep-me');
    $sentinel = HermeticitySentinel::capture($app);

    putenv('PLAIN_PRESET');

    $report = $sentinel->check($app);

    expect($report->clean())->toBeFalse()
        ->and($report->describe())->toContain('PLAIN_PRESET')
        ->and(getenv('PLAIN_PRESET'))->toBe('keep-me');

    putenv('PLAIN_PRESET');
    putenv('WARP_TEST_PRESET');
});

it('ignores WARP_ control variables', function () {
    $app = $this->createClassicApplication();
    $sentinel = HermeticitySentinel::capture($app);

    putenv('WARP_WARM=1');
    $report = $sentinel->check($app);
    putenv('WARP_WARM');

    expect($report->clean())->toBeTrue();
});

it('flags base config mutation as corruption', function () {
    $app = $this->createClassicApplication();
    $sentinel = HermeticitySentinel::capture($app);

    $app['config']->set('warp-leak.probe', 'x');

    $report = $sentinel->check($app);

    expect($report->clean())->toBeFalse()
        ->and($report->baseCorrupted)->toBeTrue();
});

it('runs custom static probes', function () {
    $app = $this->createClassicApplication();
    $flag = 'pristine';
    $sentinel = HermeticitySentinel::capture($app, [
        'custom.flag' => function () use (&$flag) {
            return $flag;
        },
    ]);

    $flag = 'mutated';

    expect($sentinel->check($app)->describe())->toContain('custom.flag');
});

it('scraps a corrupted warm base so the next sandbox reboots pristine', function () {
    $manifest = ResetManifest::default();
    $create = fn () => $this->createClassicApplication();

    WarmApplicationFactory::scrap();
    WarmApplicationFactory::sandbox($create, $manifest);
    $base1 = WarmApplicationFactory::base();

    $base1['config']->set('warp-corrupt.probe', '1');

    $report = WarmApplicationFactory::checkHermeticity();
    expect($report->baseCorrupted)->toBeTrue();

    $s2 = WarmApplicationFactory::sandbox($create, $manifest);
    expect(WarmApplicationFactory::base())->not->toBe($base1)
        ->and($s2['config']->get('warp-corrupt.probe'))->toBeNull();
});

it('returns a clean report when no warm base exists', function () {
    WarmApplicationFactory::scrap();

    expect(WarmApplicationFactory::checkHermeticity()->clean())->toBeTrue();
});
