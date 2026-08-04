<?php

declare(strict_types=1);

use RawPHP\Warp\Timing\ShardTotals;

it('uses recorded totals when roots match and keys intersect discovered files', function () {
    $totals = ['tests/ATest.php' => 10.0, 'tests/BTest.php' => 5.0];

    $decision = ShardTotals::resolve(
        storedRoot: '/app',
        canonicalRoot: '/app',
        totals: $totals,
        files: ['tests/ATest.php', 'tests/BTest.php'],
        dirLabel: '.warp/timings',
        strictRoot: false,
    );

    expect($decision->totals)->toBe($totals)
        ->and($decision->message)->toBeNull()
        ->and($decision->hardFailExit)->toBeNull();
});

it('warns and keeps empty totals when nothing was recorded', function () {
    $decision = ShardTotals::resolve(
        storedRoot: null,
        canonicalRoot: '/app',
        totals: [],
        files: ['tests/ATest.php'],
        dirLabel: 'my-timings',
        strictRoot: false,
    );

    expect($decision->totals)->toBe([])
        ->and($decision->message)->toBe('[warp] no recorded timings under my-timings - sharding count-balanced')
        ->and($decision->hardFailExit)->toBeNull();
});

it('warns when roots match but no key intersects (totals left intact for sharder)', function () {
    $totals = ['tests/OtherTest.php' => 10.0];

    $decision = ShardTotals::resolve(
        storedRoot: '/app',
        canonicalRoot: '/app',
        totals: $totals,
        files: ['tests/ATest.php'],
        dirLabel: '.warp/timings',
        strictRoot: false,
    );

    expect($decision->totals)->toBe($totals)
        ->and($decision->message)->toContain('match no discovered file')
        ->and($decision->hardFailExit)->toBeNull();
});

it('uses timings with a portable-root warning when roots differ but keys still match', function () {
    $totals = ['tests/ATest.php' => 100.0];

    $decision = ShardTotals::resolve(
        storedRoot: '/Users/dev/project',
        canonicalRoot: '/home/runner/project',
        totals: $totals,
        files: ['tests/ATest.php', 'tests/BTest.php'],
        dirLabel: '.warp/timings',
        strictRoot: false,
    );

    expect($decision->totals)->toBe($totals)
        ->and($decision->message)->toContain('root differs')
        ->and($decision->message)->toContain('using them')
        ->and($decision->hardFailExit)->toBeNull();
});

it('hard-fails under strict root when roots differ and keys match', function () {
    $decision = ShardTotals::resolve(
        storedRoot: '/Users/dev/project',
        canonicalRoot: '/home/runner/project',
        totals: ['tests/ATest.php' => 100.0],
        files: ['tests/ATest.php'],
        dirLabel: '.warp/timings',
        strictRoot: true,
    );

    expect($decision->hardFailExit)->toBe(2)
        ->and($decision->message)->toContain('WARP_STRICT_ROOT')
        ->and($decision->message)->toContain('/Users/dev/project')
        ->and($decision->message)->toContain('/home/runner/project');
});

it('empties totals when roots differ and no key matches (stale/foreign artifact)', function () {
    $decision = ShardTotals::resolve(
        storedRoot: '/old/path',
        canonicalRoot: '/new/path',
        totals: ['tests/GoneTest.php' => 50.0],
        files: ['tests/ATest.php'],
        dirLabel: '.warp/timings',
        strictRoot: false,
    );

    expect($decision->totals)->toBe([])
        ->and($decision->message)->toContain('stale or foreign')
        ->and($decision->hardFailExit)->toBeNull();
});

it('does not hard-fail under strict root when roots differ but no keys match (degrades instead)', function () {
    $decision = ShardTotals::resolve(
        storedRoot: '/old/path',
        canonicalRoot: '/new/path',
        totals: ['tests/GoneTest.php' => 50.0],
        files: ['tests/ATest.php'],
        dirLabel: '.warp/timings',
        strictRoot: true,
    );

    expect($decision->totals)->toBe([])
        ->and($decision->hardFailExit)->toBeNull()
        ->and($decision->message)->toContain('count-balanced');
});
