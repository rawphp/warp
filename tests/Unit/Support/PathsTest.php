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

it('canonical returns the root-relative key for a file inside the root, unchanged from current behavior', function () {
    mkdir($this->tmp.'/root/tests', 0755, true);
    file_put_contents($this->tmp.'/root/tests/FooTest.php', '<?php');

    expect(Paths::canonical($this->tmp.'/root/tests/FooTest.php', $this->tmp.'/root'))
        ->toBe('tests/FooTest.php');
});

it('canonical returns null when the path does not exist', function () {
    expect(Paths::canonical($this->tmp.'/root/missing/FooTest.php', $this->tmp))
        ->toBeNull();
});

it('canonical no longer accepts a per-caller allowOutside parameter (UR-017: one key policy)', function () {
    $method = new ReflectionMethod(Paths::class, 'canonical');

    expect($method->getNumberOfParameters())->toBe(2)
        ->and(array_map(fn ($p) => $p->getName(), $method->getParameters()))->toBe(['path', 'root']);
});

it('canonical relativizes an outside-root file with leading ../ segments instead of returning null or an absolute path (finding 8, UR-017)', function () {
    mkdir($this->tmp.'/root', 0755, true);
    mkdir($this->tmp.'/shared/tests', 0755, true);
    file_put_contents($this->tmp.'/shared/tests/FooTest.php', '<?php');

    expect(Paths::canonical($this->tmp.'/shared/tests/FooTest.php', $this->tmp.'/root'))
        ->toBe('../shared/tests/FooTest.php');
});

it('canonical produces byte-identical ../ keys for two independently rooted copies of the same relative layout (simulated cross-machine parity, UR-017 AC)', function () {
    $bases = [];
    $layouts = [];

    try {
        foreach (['machine-a', 'machine-b'] as $label) {
            $base = sys_get_temp_dir().'/warp-paths-parity-'.$label.'-'.bin2hex(random_bytes(4));
            $bases[] = $base;
            mkdir($base.'/project', 0755, true);
            mkdir($base.'/shared/tests', 0755, true);
            file_put_contents($base.'/shared/tests/FooTest.php', '<?php');

            $layouts[$label] = Paths::canonical($base.'/shared/tests/FooTest.php', $base.'/project');
        }

        expect($layouts['machine-a'])->toBe($layouts['machine-b'])
            ->and($layouts['machine-a'])->toBe('../shared/tests/FooTest.php');
    } finally {
        foreach ($bases as $base) {
            exec('rm -rf '.escapeshellarg($base));
        }
    }
});

it('isInside reports true only for paths that resolve inside the root, independent of the outside-root key form', function () {
    mkdir($this->tmp.'/root/tests', 0755, true);
    file_put_contents($this->tmp.'/root/tests/FooTest.php', '<?php');
    mkdir($this->tmp.'/elsewhere', 0755, true);
    file_put_contents($this->tmp.'/elsewhere/BarTest.php', '<?php');

    expect(Paths::isInside($this->tmp.'/root/tests/FooTest.php', $this->tmp.'/root'))->toBeTrue()
        ->and(Paths::isInside($this->tmp.'/elsewhere/BarTest.php', $this->tmp.'/root'))->toBeFalse()
        ->and(Paths::isInside($this->tmp.'/root', $this->tmp.'/root'))->toBeTrue()
        ->and(Paths::isInside($this->tmp.'/missing/BazTest.php', $this->tmp.'/root'))->toBeFalse();
});

it('absolute treats a leading-slash path as already absolute, unchanged (REQ-107)', function () {
    expect(Paths::absolute('/already/absolute', '/some/base'))->toBe('/already/absolute');
});

it('absolute treats a Windows drive-letter path with a backslash separator as absolute, unchanged (finding 10, REQ-107)', function () {
    expect(Paths::absolute('C:\\timings', '/some/base'))->toBe('C:\\timings');
});

it('absolute treats a Windows drive-letter path with a forward-slash separator as absolute, unchanged (finding 10, REQ-107)', function () {
    expect(Paths::absolute('C:/timings', '/some/base'))->toBe('C:/timings');
});

it('absolute joins a relative path onto a base without a trailing separator (REQ-107)', function () {
    expect(Paths::absolute('relative/dir', '/some/base'))->toBe('/some/base/relative/dir');
});

it('absolute joins a relative path onto a base that already has a trailing separator (REQ-107)', function () {
    expect(Paths::absolute('relative/dir', '/some/base/'))->toBe('/some/base/relative/dir');
});

it('has exactly one drive-letter absolute-path regex in the codebase, in Paths (REQ-107, finding 14)', function () {
    $srcDir = dirname(__DIR__, 3).'/src';
    $count = 0;

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $count += substr_count((string) file_get_contents($file->getPathname()), '^[A-Za-z]:');
    }

    expect($count)->toBe(1);
});
