<?php

declare(strict_types=1);

namespace RawPHP\Warp\Support {
    if (! function_exists(__NAMESPACE__.'\\file_put_contents')) {
        function file_put_contents($filename, $data, $flags = 0, $context = null): int|false
        {
            if (\AtomicWriteShortWrite::enabled()) {
                return \AtomicWriteShortWrite::write($filename, (string) $data, $flags, $context);
            }

            return \file_put_contents($filename, $data, $flags, $context);
        }
    }
}

namespace {
    use RawPHP\Warp\Db\Dirs;
    use RawPHP\Warp\Support\AtomicFile;

    if (! class_exists(AtomicWriteShortWrite::class, false)) {
        final class AtomicWriteShortWrite
        {
            private static ?int $bytes = null;

            public static function enable(int $bytes): void
            {
                self::$bytes = $bytes;
            }

            public static function disable(): void
            {
                self::$bytes = null;
            }

            public static function enabled(): bool
            {
                return self::$bytes !== null;
            }

            public static function enabledFor(string $path): bool
            {
                return self::$bytes !== null && str_ends_with($path, '.json.tmp');
            }

            public static function write(string $path, string $data, int $flags = 0, $context = null): int|false
            {
                $bytes = min(self::$bytes ?? strlen($data), strlen($data) - 1);

                $result = \file_put_contents($path, substr($data, 0, $bytes), $flags, $context);

                return $result === false ? false : $bytes;
            }
        }
    }

    beforeEach(function () {
        $this->dir = sys_get_temp_dir().'/warp-atomic-file-'.bin2hex(random_bytes(4));
        Dirs::ensure($this->dir);
    });

    afterEach(function () {
        AtomicWriteShortWrite::disable();
        Dirs::delete($this->dir);
    });

    it('writes contents through a temporary file and publishes atomically', function () {
        $path = $this->dir.'/data.json';

        AtomicFile::write($path, '{"ok":true}', '[warp] cannot write test file', '[warp] cannot publish test file');

        expect(file_get_contents($path))->toBe('{"ok":true}')
            ->and(is_file($path.'.tmp'))->toBeFalse();
    });

    it('cleans up a short-written temporary file and keeps the target unchanged', function () {
        $path = $this->dir.'/data.json';
        file_put_contents($path, '{"old":true}');

        AtomicWriteShortWrite::enable(4);

        expect(fn () => AtomicFile::write($path, '{"new":true}', '[warp] cannot write test file', '[warp] cannot publish test file'))
            ->toThrow(RuntimeException::class, '[warp] cannot write test file');

        expect(file_get_contents($path))->toBe('{"old":true}')
            ->and(is_file($path.'.tmp'))->toBeFalse();
    });
}
