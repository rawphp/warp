<?php

declare(strict_types=1);

use RawPHP\Warp\Support\Paths;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/warp-paths-'.bin2hex(random_bytes(4));
    mkdir($this->tmp, 0755, true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->tmp));
});

it('configRoot returns the cwd when no configuration file is given', function () {
    expect(Paths::configRoot(null, '/some/cwd'))->toBe('/some/cwd');
});

it('configRoot returns the real directory of the configuration file', function () {
    $configDir = $this->tmp.'/config';
    mkdir($configDir, 0755, true);
    file_put_contents($configDir.'/phpunit.xml', '<phpunit/>');

    expect(Paths::configRoot($configDir.'/phpunit.xml', '/unused/cwd'))
        ->toBe(realpath($configDir));
});

it('configRoot resolves a symlinked configuration file to its real directory (finding 4)', function () {
    $realDir = $this->tmp.'/real';
    mkdir($realDir, 0755, true);
    file_put_contents($realDir.'/phpunit.xml', '<phpunit/>');

    $linkDir = $this->tmp.'/link';
    mkdir($linkDir, 0755, true);
    symlink($realDir.'/phpunit.xml', $linkDir.'/phpunit.xml');

    // The write side and read side must agree: the canonical root is the config
    // file's REAL directory, not the directory the symlink lives in.
    expect(Paths::configRoot($linkDir.'/phpunit.xml', '/unused/cwd'))
        ->toBe(realpath($realDir));
});

it('configRoot falls back to the raw path directory when realpath fails', function () {
    expect(Paths::configRoot('/does/not/exist/phpunit.xml', '/unused/cwd'))
        ->toBe('/does/not/exist');
});
