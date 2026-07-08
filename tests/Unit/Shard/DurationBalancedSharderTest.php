<?php

declare(strict_types=1);

use RawPHP\Warp\Shard\DurationBalancedSharder;

it('isolates a dominant file so the remaining shards stay balanced', function () {
    $files = ['tests/ATest.php', 'tests/BTest.php', 'tests/CTest.php', 'tests/DTest.php'];
    $totals = ['tests/ATest.php' => 100.0, 'tests/BTest.php' => 10.0, 'tests/CTest.php' => 10.0, 'tests/DTest.php' => 10.0];

    expect(DurationBalancedSharder::assign($files, $totals, 1, 2))->toBe(['tests/ATest.php'])
        ->and(DurationBalancedSharder::assign($files, $totals, 2, 2))->toBe(['tests/BTest.php', 'tests/CTest.php', 'tests/DTest.php']);
});

it('shards are disjoint and cover every file', function () {
    $files = ['tests/ATest.php', 'tests/BTest.php', 'tests/CTest.php', 'tests/DTest.php', 'tests/ETest.php'];
    $totals = ['tests/ATest.php' => 50.0, 'tests/BTest.php' => 40.0, 'tests/CTest.php' => 30.0, 'tests/DTest.php' => 20.0, 'tests/ETest.php' => 10.0];

    $all = [];

    foreach ([1, 2, 3] as $index) {
        $shard = DurationBalancedSharder::assign($files, $totals, $index, 3);

        expect(array_intersect($all, $shard))->toBe([]);

        $all = [...$all, ...$shard];
    }

    sort($all);

    expect($all)->toBe($files);
});

it('weighs unmeasured files at the average of the known totals', function () {
    $files = ['tests/ATest.php', 'tests/BTest.php', 'tests/CTest.php'];
    $totals = ['tests/ATest.php' => 30.0];

    expect(DurationBalancedSharder::assign($files, $totals, 1, 3))->toHaveCount(1)
        ->and(DurationBalancedSharder::assign($files, $totals, 2, 3))->toHaveCount(1)
        ->and(DurationBalancedSharder::assign($files, $totals, 3, 3))->toHaveCount(1);
});

it('degrades to count-balanced round-robin with no timings at all', function () {
    $files = ['tests/ATest.php', 'tests/BTest.php', 'tests/CTest.php', 'tests/DTest.php'];

    expect(DurationBalancedSharder::assign($files, [], 1, 2))->toBe(['tests/ATest.php', 'tests/CTest.php'])
        ->and(DurationBalancedSharder::assign($files, [], 2, 2))->toBe(['tests/BTest.php', 'tests/DTest.php']);
});

it('ignores totals for files not in the given list', function () {
    $files = ['tests/ATest.php', 'tests/BTest.php'];
    $totals = ['tests/DeletedTest.php' => 9999.0, 'tests/ATest.php' => 10.0, 'tests/BTest.php' => 10.0];

    expect(DurationBalancedSharder::assign($files, $totals, 1, 2))->toBe(['tests/ATest.php']);
});

it('returns an empty shard when there are more shards than files', function () {
    expect(DurationBalancedSharder::assign(['tests/ATest.php'], [], 2, 3))->toBe([]);
});

it('is deterministic across repeated calls', function () {
    $files = ['tests/ATest.php', 'tests/BTest.php', 'tests/CTest.php'];
    $totals = ['tests/ATest.php' => 5.0, 'tests/BTest.php' => 5.0, 'tests/CTest.php' => 5.0];

    expect(DurationBalancedSharder::assign($files, $totals, 1, 2))
        ->toBe(DurationBalancedSharder::assign($files, $totals, 1, 2));
});

it('rejects out-of-range shard specs', function (int $index, int $total) {
    DurationBalancedSharder::assign(['tests/ATest.php'], [], $index, $total);
})->with([[0, 2], [3, 2], [1, 0]])->throws(InvalidArgumentException::class, '[warp] shard index out of range');
