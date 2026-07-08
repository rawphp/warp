<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Db\GoldenSnapshotBuilder;
use RawPHP\Warp\Db\MysqlBinaries;
use RawPHP\Warp\Db\SnapshotStore;

beforeEach(function () {
    if (! mysqldAvailable()) {
        $this->markTestSkipped('mysqld not found — install MySQL 8 or set WARP_DB_MYSQLD');
    }
    if (! extension_loaded('pdo_mysql')) {
        $this->markTestSkipped('pdo_mysql not loaded');
    }

    $this->root = sys_get_temp_dir().'/warp-gold-'.bin2hex(random_bytes(4));
    $this->store = new SnapshotStore($this->root);
    $this->builder = new GoldenSnapshotBuilder(MysqlBinaries::discover(), $this->store);
    $this->key = sha1('golden-test-'.bin2hex(random_bytes(4)));
});

afterEach(function () {
    if (isset($this->root)) {
        Dirs::delete($this->root);
    }
});

it('builds, seeds, and promotes a golden datadir with metadata', function () {
    $this->builder->build($this->key, 'warp_golden', function (string $socket, string $database): void {
        $pdo = new PDO("mysql:unix_socket={$socket};dbname={$database}", 'root', '');
        $pdo->exec('CREATE TABLE marks (id INT PRIMARY KEY)');
        $pdo->exec('INSERT INTO marks VALUES (1)');
    });

    expect($this->store->exists($this->key))->toBeTrue()
        ->and(is_dir($this->store->datadir($this->key).'/warp_golden'))->toBeTrue()
        ->and(json_decode((string) file_get_contents($this->store->path($this->key).'/meta.json'), true))
            ->toHaveKeys(['key', 'database', 'mysqld_version', 'format', 'built_at'])
        ->and(glob($this->root.'/*.staging-*'))->toBe([]);
});

it('short-circuits when the snapshot already exists', function () {
    $seeds = 0;
    $seed = function (string $socket, string $database) use (&$seeds): void {
        $seeds++;
        $pdo = new PDO("mysql:unix_socket={$socket};dbname={$database}", 'root', '');
        $pdo->exec('CREATE TABLE marks (id INT PRIMARY KEY)');
    };

    $this->builder->build($this->key, 'warp_golden', $seed);
    $this->builder->build($this->key, 'warp_golden', $seed);

    expect($seeds)->toBe(1);
});

it('throws and does not promote when the migrated datadir has no schema for the target database', function () {
    try {
        // Simulates the DB_HOST/DB_PORT footgun: the build subprocess exits 0
        // (e.g. it "migrated" against the wrong server) but never seeds this datadir.
        $this->builder->build($this->key, 'warp_golden', function (string $socket, string $database): void {
        });
        $this->fail('expected build() to throw when no schema landed in the built datadir');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('warp_golden');
    }

    expect($this->store->exists($this->key))->toBeFalse()
        ->and(glob($this->root.'/*.staging-*'))->toBe([]);
});

it('cleans staging and rethrows when the seed callable fails', function () {
    try {
        $this->builder->build($this->key, 'warp_golden', function (): void {
            throw new RuntimeException('seed exploded');
        });
        $this->fail('expected the seed failure to propagate');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('seed exploded');
    }

    expect($this->store->exists($this->key))->toBeFalse()
        ->and(glob($this->root.'/*.staging-*'))->toBe([]);
});
