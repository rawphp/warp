<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Db\SnapshotKey;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/warp-key-'.bin2hex(random_bytes(4));
    Dirs::ensure($this->tmp.'/migrations');
    file_put_contents($this->tmp.'/migrations/001_users.php', 'create users');
    file_put_contents($this->tmp.'/migrations/002_orders.php', 'create orders');

    $this->compute = fn (): string => SnapshotKey::compute(
        [$this->tmp.'/migrations'],
        'mysqld  Ver 8.4.0',
        'app_test',
        ['php', 'artisan', 'migrate', '--force'],
    );
});

afterEach(function () {
    Dirs::delete($this->tmp);
});

it('is deterministic for identical inputs', function () {
    expect(($this->compute)())->toBe(($this->compute)());
});

it('returns a 40-char sha1 hex string', function () {
    expect(($this->compute)())->toMatch('/^[0-9a-f]{40}$/');
});

it('changes when a hashed file content changes', function () {
    $before = ($this->compute)();
    file_put_contents($this->tmp.'/migrations/001_users.php', 'create users v2');

    expect(($this->compute)())->not->toBe($before);
});

it('changes when a hashed file is renamed', function () {
    $before = ($this->compute)();
    rename($this->tmp.'/migrations/002_orders.php', $this->tmp.'/migrations/003_orders.php');

    expect(($this->compute)())->not->toBe($before);
});

it('changes with the mysqld version', function () {
    $before = ($this->compute)();
    $after = SnapshotKey::compute([$this->tmp.'/migrations'], 'mysqld  Ver 8.0.39', 'app_test', ['php', 'artisan', 'migrate', '--force']);

    expect($after)->not->toBe($before);
});

it('changes with the database name and build command', function () {
    $base = ($this->compute)();

    expect(SnapshotKey::compute([$this->tmp.'/migrations'], 'mysqld  Ver 8.4.0', 'other_db', ['php', 'artisan', 'migrate', '--force']))->not->toBe($base)
        ->and(SnapshotKey::compute([$this->tmp.'/migrations'], 'mysqld  Ver 8.4.0', 'app_test', ['php', 'artisan', 'migrate', '--seed', '--force']))->not->toBe($base);
});

it('silently skips hash paths that do not exist', function () {
    $with = SnapshotKey::compute([$this->tmp.'/migrations', $this->tmp.'/seeders'], 'v', 'db', []);
    $without = SnapshotKey::compute([$this->tmp.'/migrations'], 'v', 'db', []);

    expect($with)->toBe($without);
});
