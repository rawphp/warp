<?php

declare(strict_types=1);

use RawPHP\Warp\Timing\TimingsMerge;

it('aggregates per-file totals and sorts paths', function () {
    $totals = TimingsMerge::aggregate([
        'B::test' => ['file' => 'tests/B.php', 'ms' => 2.5],
        'A::one' => ['file' => 'tests/A.php', 'ms' => 1.0],
        'A::two' => ['file' => 'tests/A.php', 'ms' => 3.0],
    ]);

    expect($totals)->toBe([
        'tests/A.php' => 4.0,
        'tests/B.php' => 2.5,
    ]);
});

it('indexes tests by file', function () {
    $index = TimingsMerge::indexByFile([
        'A::one' => ['file' => 'a.php', 'ms' => 1.0],
        'A::two' => ['file' => 'a.php', 'ms' => 2.0],
        'B::one' => ['file' => 'b.php', 'ms' => 3.0],
    ]);

    expect($index)->toBe([
        'a.php' => ['A::one' => true, 'A::two' => true],
        'b.php' => ['B::one' => true],
    ]);
});

it('sanitizes junk entries and coerces ms to float', function () {
    $clean = TimingsMerge::sanitizeTests([
        'ok' => ['file' => 'a.php', 'ms' => '1.5'],
        42 => ['file' => 'a.php', 'ms' => 1.0],
        'bad-ms' => ['file' => 'a.php', 'ms' => 'nope'],
        'inf' => ['file' => 'a.php', 'ms' => INF],
        'missing' => ['file' => 'a.php'],
    ]);

    expect($clean)->toBe(['ok' => ['file' => 'a.php', 'ms' => 1.5]]);
});

it('treats non-map complete as no complete files', function () {
    expect(TimingsMerge::completeFilesOf(['complete' => true]))->toBe([])
        ->and(TimingsMerge::completeFilesOf(['complete' => ['a.php' => true, 'b.php' => false]]))
        ->toBe(['a.php']);
});

it('supersedes only complete files and upserts the rest without mutating inputs', function () {
    $tests = [
        'Old::a' => ['file' => 'a.php', 'ms' => 10.0],
        'Old::b' => ['file' => 'b.php', 'ms' => 20.0],
        'Keep::c' => ['file' => 'c.php', 'ms' => 30.0],
    ];
    $index = TimingsMerge::indexByFile($tests);
    $indexBefore = $index;

    $merged = TimingsMerge::apply($tests, $index, [
        'complete' => ['a.php' => true],
        'tests' => [
            'New::a' => ['file' => 'a.php', 'ms' => 1.0],
            'Old::b' => ['file' => 'b.php', 'ms' => 2.0],
        ],
    ]);

    expect($merged['tests'])->toBe([
        'Old::b' => ['file' => 'b.php', 'ms' => 2.0],
        'Keep::c' => ['file' => 'c.php', 'ms' => 30.0],
        'New::a' => ['file' => 'a.php', 'ms' => 1.0],
    ])
        ->and($merged['fileIndex'])->toEqual(TimingsMerge::indexByFile($merged['tests']))
        ->and($index)->toBe($indexBefore)
        ->and($tests['Old::a']['ms'])->toBe(10.0);
});
