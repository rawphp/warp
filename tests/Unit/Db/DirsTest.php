<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/warp-dirs-'.bin2hex(random_bytes(4));
    Dirs::installTestBeforeFsOp(null);
});

afterEach(function () {
    Dirs::installTestBeforeFsOp(null);
    if (isset($this->tmp) && (file_exists($this->tmp) || is_link($this->tmp))) {
        // Bypass hooks so cleanup cannot re-enter race simulations.
        Dirs::delete($this->tmp);
    }
});

/**
 * Convert PHP warnings into ErrorException — mirrors Laravel's testing handler
 * that turns bare unlink/rmdir races into hard test failures.
 */
function convertWarningsToErrorException(): void
{
    set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}

it('ensures a nested directory exists', function () {
    Dirs::ensure($this->tmp.'/a/b/c');

    expect(is_dir($this->tmp.'/a/b/c'))->toBeTrue();
});

it('ensure is idempotent', function () {
    Dirs::ensure($this->tmp.'/a');
    Dirs::ensure($this->tmp.'/a');

    expect(is_dir($this->tmp.'/a'))->toBeTrue();
});

it('deletes a nested tree with files', function () {
    Dirs::ensure($this->tmp.'/a/b');
    file_put_contents($this->tmp.'/a/b/file.txt', 'x');
    file_put_contents($this->tmp.'/a/top.txt', 'y');

    Dirs::delete($this->tmp.'/a');

    expect(file_exists($this->tmp.'/a'))->toBeFalse();
});

it('delete is a silent no-op on a missing path', function () {
    Dirs::delete($this->tmp.'/nope');

    expect(true)->toBeTrue();
});

it('delete removes a plain file too', function () {
    Dirs::ensure($this->tmp);
    file_put_contents($this->tmp.'/f.txt', 'x');

    Dirs::delete($this->tmp.'/f.txt');

    expect(file_exists($this->tmp.'/f.txt'))->toBeFalse();
});

it('does not expose public beforeFsOp on the package surface', function () {
    $ref = new ReflectionClass(Dirs::class);

    if ($ref->hasProperty('beforeFsOp')) {
        expect($ref->getProperty('beforeFsOp')->isPublic())->toBeFalse();
    } else {
        expect($ref->hasProperty('beforeFsOp'))->toBeFalse();
    }

    // Static analysis / package surface: no public static $beforeFsOp property.
    $publicStaticProps = array_map(
        static fn (ReflectionProperty $p) => $p->getName(),
        $ref->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_STATIC)
    );

    expect($publicStaticProps)->not->toContain('beforeFsOp');
});

it('documents not-empty backoff as a 10–20ms named constant', function () {
    $ref = new ReflectionClass(Dirs::class);
    expect($ref->hasConstant('NOT_EMPTY_BACKOFF_US'))->toBeTrue();

    $us = $ref->getConstant('NOT_EMPTY_BACKOFF_US');
    expect($us)->toBeInt()
        ->and($us)->toBeGreaterThanOrEqual(10_000)
        ->and($us)->toBeLessThanOrEqual(20_000);
});

it('treats mid-walk unlink ENOENT as success without ErrorException', function () {
    convertWarningsToErrorException();

    try {
        $dir = $this->tmp.'/vanish';
        Dirs::ensure($dir);
        file_put_contents($dir.'/keep.txt', 'x');
        file_put_contents($dir.'/ghost.txt', 'y');

        // Simulate concurrent FS churn: file disappears between readdir and unlink.
        Dirs::installTestBeforeFsOp(static function (string $op, string $path): void {
            if ($op === 'unlink' && str_ends_with($path, 'ghost.txt') && is_file($path)) {
                unlink($path);
            }
        });

        Dirs::delete($dir);

        expect(file_exists($dir))->toBeFalse();
    } finally {
        restore_error_handler();
    }
});

it('retries rmdir when directory is temporarily non-empty then succeeds', function () {
    convertWarningsToErrorException();

    try {
        $dir = $this->tmp.'/not-empty-race';
        Dirs::ensure($dir);
        file_put_contents($dir.'/seed.txt', 'x');

        $rmdirAttempts = 0;

        // First rmdir: re-create a child so rmdir fails with "Directory not empty".
        // Subsequent attempts leave the tree quiet so clearChildren + rmdir succeed.
        Dirs::installTestBeforeFsOp(static function (string $op, string $path) use ($dir, &$rmdirAttempts): void {
            if ($op !== 'rmdir' || $path !== $dir) {
                return;
            }

            $rmdirAttempts++;

            if ($rmdirAttempts === 1) {
                file_put_contents($dir.'/late.txt', 'late');
            }
        });

        Dirs::delete($dir);

        expect(file_exists($dir))->toBeFalse()
            ->and($rmdirAttempts)->toBeGreaterThanOrEqual(2);
    } finally {
        restore_error_handler();
    }
});

it('does not silence non-ENOENT unlink failures', function () {
    Dirs::ensure($this->tmp.'/locked');
    $file = $this->tmp.'/locked/secret.txt';
    file_put_contents($file, 'x');
    chmod($this->tmp.'/locked', 0555);

    try {
        expect(static fn () => Dirs::delete($file))
            ->toThrow(RuntimeException::class, '[warp]');
    } finally {
        chmod($this->tmp.'/locked', 0755);
    }
});

it('throws [warp] RuntimeException after exhausting not-empty retries', function () {
    $dir = $this->tmp.'/sticky';
    Dirs::ensure($dir);
    file_put_contents($dir.'/seed.txt', 'x');

    // Every rmdir attempt re-creates a child — never becomes empty.
    Dirs::installTestBeforeFsOp(static function (string $op, string $path) use ($dir): void {
        if ($op === 'rmdir' && $path === $dir && is_dir($dir)) {
            file_put_contents($dir.'/sticky.txt', 'sticky');
        }
    });

    expect(static fn () => Dirs::delete($dir))
        ->toThrow(RuntimeException::class, '[warp]');

    // Leave tree removable for afterEach cleanup.
    Dirs::installTestBeforeFsOp(null);
});

it('uses a single removeNode-style pipeline (no separate public unlinkPath/tryRmdir)', function () {
    $ref = new ReflectionClass(Dirs::class);
    $methods = array_map(
        static fn (ReflectionMethod $m) => $m->getName(),
        $ref->getMethods()
    );

    // Shared pipeline method exists under removeNode (or equivalent private name).
    $hasRemoveNode = $ref->hasMethod('removeNode');
    expect($hasRemoveNode)->toBeTrue();

    // Old split pipelines must not remain as separate methods once collapsed.
    expect($methods)->not->toContain('unlinkPath')
        ->and($methods)->not->toContain('tryRmdir');
});
