<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing {
    if (! function_exists(__NAMESPACE__.'\\file_get_contents')) {
        function file_get_contents($filename, $use_include_path = false, $context = null, $offset = 0, $length = null): string|false
        {
            if (\TimingStorePendingReadRace::enabledFor($filename)) {
                return \TimingStorePendingReadRace::read($filename);
            }

            if ($length === null) {
                return \file_get_contents($filename, $use_include_path, $context, $offset);
            }

            return \file_get_contents($filename, $use_include_path, $context, $offset, $length);
        }
    }

    if (! function_exists(__NAMESPACE__.'\\file_put_contents')) {
        function file_put_contents($filename, $data, $flags = 0, $context = null): int|false
        {
            if (\AtomicWriteShortWrite::enabledFor($filename)) {
                return \AtomicWriteShortWrite::write($filename, (string) $data, $flags, $context);
            }

            return \file_put_contents($filename, $data, $flags, $context);
        }
    }
}

namespace RawPHP\Warp\Support {
    if (! function_exists(__NAMESPACE__.'\\file_put_contents')) {
        function file_put_contents($filename, $data, $flags = 0, $context = null): int|false
        {
            if (\AtomicWriteShortWrite::enabledFor($filename)) {
                return \AtomicWriteShortWrite::write($filename, (string) $data, $flags, $context);
            }

            return \file_put_contents($filename, $data, $flags, $context);
        }
    }
}

namespace {
    use RawPHP\Warp\Db\Dirs;
    use RawPHP\Warp\Support\Stderr;
    use RawPHP\Warp\Timing\TimingStore;

    if (! class_exists(TimingStorePendingReadRace::class, false)) {
        final class TimingStorePendingReadRace
        {
            private static ?string $dir = null;

            private static ?string $path = null;

            private static bool $triggered = false;

            public static function enable(string $dir, string $path): void
            {
                self::$dir = $dir;
                self::$path = $path;
                self::$triggered = false;
            }

            public static function disable(): void
            {
                self::$dir = null;
                self::$path = null;
                self::$triggered = false;
            }

            public static function triggered(): bool
            {
                return self::$triggered;
            }

            public static function enabledFor(string $path): bool
            {
                return self::$dir !== null
                    && self::$path === $path
                    && ! self::$triggered;
            }

            public static function read(string $path): string|false
            {
                self::$triggered = true;

                $batch = json_decode((string) \file_get_contents($path), true);
                \file_put_contents(self::$dir.'/timings.json', json_encode([
                    'version' => 2,
                    'tests' => is_array($batch) && is_array($batch['tests'] ?? null) ? $batch['tests'] : [],
                ], JSON_THROW_ON_ERROR));
                \unlink($path);

                return false;
            }
        }
    }

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
        $this->dir = sys_get_temp_dir().'/warp-timings-'.bin2hex(random_bytes(4));
        $this->store = new TimingStore($this->dir);
    });

    afterEach(function () {
        AtomicWriteShortWrite::disable();
        TimingStorePendingReadRace::disable();
        putenv('WARP_TIMINGS_DIR');
        Dirs::delete($this->dir);
    });

    it('loads empty when nothing was ever recorded, without creating directories', function () {
        expect($this->store->load())->toBe([])
            ->and(is_dir($this->dir))->toBeFalse();
    });

    it('writePending is a no-op for an empty batch', function () {
        $this->store->writePending([]);

        expect(is_dir($this->dir))->toBeFalse();
    });

    it('loads pending batches as an in-memory overlay without clearing them', function () {
        $this->store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);

        expect(glob($this->dir.'/pending/*.json'))->toHaveCount(1);

        $tests = $this->store->load();

        expect($tests)->toBe(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]])
            ->and(glob($this->dir.'/pending/*.json'))->toHaveCount(1)
            ->and(is_file($this->dir.'/merge.lock'))->toBeFalse()
            ->and(is_file($this->dir.'/timings.json'))->toBeFalse();
    });

    it('mergeToDisk merges pending batches into the store and clears them', function () {
        $this->store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);

        expect(glob($this->dir.'/pending/*.json'))->toHaveCount(1);

        $this->store->mergeToDisk();

        expect($this->store->load())->toBe(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]])
            ->and(glob($this->dir.'/pending/*.json'))->toBe([])
            ->and(is_file($this->dir.'/merge.lock'))->toBeTrue()
            ->and(is_file($this->dir.'/timings.json'))->toBeTrue();
    });

    it('does not expose the removed merge wrapper', function () {
        expect(method_exists(TimingStore::class, 'merge'.'Pending'))->toBeFalse();
    });

    it('keeps stderr warning writes centralized in one support helper', function () {
        $src = dirname(__DIR__, 3).'/src';
        $sources = '';

        foreach ([
            $src.'/Timing/TimingStore.php',
            $src.'/Timing/TimingExtension.php',
            $src.'/Support/Stderr.php',
        ] as $file) {
            $sources .= is_file($file) ? (string) file_get_contents($file) : '';
        }

        expect(class_exists(Stderr::class))->toBeTrue()
            ->and(substr_count($sources, 'function warn('))->toBe(0)
            ->and(substr_count($sources, 'function write('))->toBe(1);
    });

    it('loads from a read-only directory with pending batches without writing a lock or clearing pending', function () {
        Dirs::ensure($this->dir.'/pending');

        file_put_contents($this->dir.'/timings.json', json_encode([
            'version' => 2,
            'tests' => [
                'old' => ['file' => 'tests/OldTest.php', 'ms' => 100.0],
                'stale' => ['file' => 'tests/FooTest.php', 'ms' => 5000.0],
            ],
        ]));

        file_put_contents($this->dir.'/pending/100-1-aabbccdd.json', json_encode([
            'complete' => true,
            'tests' => [
                'fresh' => ['file' => 'tests/FooTest.php', 'ms' => 50.0],
            ],
        ]));

        chmod($this->dir.'/pending', 0555);
        chmod($this->dir, 0555);

        try {
            $tests = $this->store->load();
        } finally {
            chmod($this->dir, 0755);
            chmod($this->dir.'/pending', 0755);
        }

        expect($tests)->toBe([
            'old' => ['file' => 'tests/OldTest.php', 'ms' => 100.0],
            'fresh' => ['file' => 'tests/FooTest.php', 'ms' => 50.0],
        ])
            ->and(glob($this->dir.'/pending/*.json'))->toHaveCount(1)
            ->and(is_file($this->dir.'/merge.lock'))->toBeFalse();
    });

    it('writes pending batches atomically and leaves no temporary file behind', function () {
        $this->store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);

        $files = array_values(array_diff(scandir($this->dir.'/pending') ?: [], ['.', '..']));

        expect($files)->toHaveCount(1)
            ->and($files[0])->toMatch('/^\d{16,}-\d+-[a-f0-9]{8}\.json$/')
            ->and($files[0])->not->toEndWith('.tmp')
            ->and(json_decode((string) file_get_contents($this->dir.'/pending/'.$files[0]), true))->toBe([
                'complete' => true,
                'root' => null,
                'tests' => ['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]],
            ]);
    });

    it('treats a short pending batch write as a failed write and does not publish it', function () {
        AtomicWriteShortWrite::enable(8);

        expect(fn () => $this->store->writePending([
            't1' => ['file' => 'tests/ATest.php', 'ms' => 10.5],
        ]))->toThrow(RuntimeException::class, '[warp] cannot write pending timings batch');

        $files = array_values(array_diff(scandir($this->dir.'/pending') ?: [], ['.', '..']));

        expect($files)->toBe([]);
    });

    it('treats a short merged timings write as a failed write and does not publish it', function () {
        Dirs::ensure($this->dir.'/pending');
        file_put_contents($this->dir.'/timings.json', json_encode([
            'version' => 2,
            'tests' => ['old' => ['file' => 'tests/OldTest.php', 'ms' => 99.0]],
        ]));
        file_put_contents($this->dir.'/pending/100-1-aabbccdd.json', json_encode([
            'complete' => true,
            'tests' => ['new' => ['file' => 'tests/NewTest.php', 'ms' => 10.5]],
        ]));

        $original = (string) file_get_contents($this->dir.'/timings.json');

        AtomicWriteShortWrite::enable(8);

        expect(fn () => $this->store->mergeToDisk())
            ->toThrow(RuntimeException::class, '[warp] cannot write merged timings');

        expect(file_get_contents($this->dir.'/timings.json'))->toBe($original)
            ->and(glob($this->dir.'/pending/*.json'))->toHaveCount(1)
            ->and(is_file($this->dir.'/timings.json.tmp'))->toBeFalse();
    });

    it('names pending batches with monotonically increasing timestamp prefixes', function () {
        $this->store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);
        usleep(1000);
        $this->store->writePending(['t2' => ['file' => 'tests/BTest.php', 'ms' => 20.5]]);

        $files = array_values(array_diff(scandir($this->dir.'/pending') ?: [], ['.', '..']));

        expect($files)->toHaveCount(2);

        $timestamps = array_map(
            static fn (string $file): int => (int) strtok($file, '-'),
            $files,
        );

        expect($timestamps[0])->toBeLessThan($timestamps[1]);
    });

    it('a rerun of a file supersedes all of that file\'s previous entries', function () {
        $this->store->writePending([
            't1' => ['file' => 'tests/ATest.php', 'ms' => 10.5],
            't2' => ['file' => 'tests/ATest.php', 'ms' => 20.5],
            't3' => ['file' => 'tests/BTest.php', 'ms' => 30.5],
        ]);
        $this->store->mergeToDisk();

        // t2 was renamed/deleted since: the fresh run of ATest.php only has t1.
        $this->store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 11.5]]);

        $tests = $this->store->load();

        expect($tests)->toHaveKeys(['t1', 't3'])
            ->and($tests)->not->toHaveKey('t2')
            ->and($tests['t1']['ms'])->toBe(11.5)
            ->and($tests['t3']['ms'])->toBe(30.5);
    });

    it('incomplete pending batches merge by test id without superseding a whole file', function () {
        Dirs::ensure($this->dir);
        file_put_contents($this->dir.'/timings.json', json_encode([
            'version' => 2,
            'tests' => [
                'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
                'FileA::two' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
                'FileA::three' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
            ],
        ]));

        Dirs::ensure($this->dir.'/pending');
        file_put_contents($this->dir.'/pending/100-1-aabbccdd.json', json_encode([
            'complete' => false,
            'tests' => [
                'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 100.0],
            ],
        ]));

        expect($this->store->load())->toEqual([
            'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 100.0],
            'FileA::two' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
            'FileA::three' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
        ]);
    });

    it('complete pending batches keep superseding all previous entries for covered files', function () {
        Dirs::ensure($this->dir);
        file_put_contents($this->dir.'/timings.json', json_encode([
            'version' => 2,
            'tests' => [
                'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
                'FileA::two' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
                'FileA::three' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
            ],
        ]));

        Dirs::ensure($this->dir.'/pending');
        file_put_contents($this->dir.'/pending/100-1-aabbccdd.json', json_encode([
            'complete' => true,
            'tests' => [
                'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 100.0],
            ],
        ]));

        expect($this->store->load())->toEqual([
            'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 100.0],
        ]);
    });

    it('mergeToDisk and load produce identical merged data for the same fixture', function () {
        $seed = [
            'version' => 2,
            'tests' => [
                'FileA::old' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
                'FileB::old' => ['file' => 'tests/FileBTest.php', 'ms' => 2000.0],
            ],
        ];
        $oldBatch = [
            'complete' => true,
            'tests' => [
                'FileA::fresh' => ['file' => 'tests/FileATest.php', 'ms' => 10.0],
            ],
        ];
        $partialBatch = [
            'complete' => false,
            'tests' => [
                'FileB::new' => ['file' => 'tests/FileBTest.php', 'ms' => 20.0],
            ],
        ];

        $overlayDir = $this->dir.'/overlay';
        $mergeDir = $this->dir.'/merge';

        foreach ([$overlayDir, $mergeDir] as $dir) {
            Dirs::ensure($dir.'/pending');
            file_put_contents($dir.'/timings.json', json_encode($seed));
            file_put_contents($dir.'/pending/100-1-aabbccdd.json', json_encode($oldBatch));
            file_put_contents($dir.'/pending/200-1-bbccddee.json', json_encode($partialBatch));
        }

        $overlay = (new TimingStore($overlayDir))->load();
        $mergeStore = new TimingStore($mergeDir);
        $mergeStore->mergeToDisk();

        expect($mergeStore->load())->toBe($overlay);
    });

    it('merges pending batches by numeric timestamp when older filename sorts first lexicographically', function () {
        Dirs::ensure($this->dir.'/pending');
        file_put_contents($this->dir.'/pending/100-99999-aaaaaaaa.json', json_encode([
            'complete' => true,
            'tests' => ['old' => ['file' => 'tests/FooTest.php', 'ms' => 5000.0]],
        ]));
        file_put_contents($this->dir.'/pending/200-1-bbbbbbbb.json', json_encode([
            'complete' => true,
            'tests' => ['new' => ['file' => 'tests/FooTest.php', 'ms' => 50.0]],
        ]));

        expect($this->store->fileTotals())->toBe(['tests/FooTest.php' => 50.0]);
    });

    it('merges pending batches by numeric timestamp when older filename sorts after newer lexicographically', function () {
        Dirs::ensure($this->dir.'/pending');
        file_put_contents($this->dir.'/pending/9-99999-aaaaaaaa.json', json_encode([
            'complete' => true,
            'tests' => ['old' => ['file' => 'tests/FooTest.php', 'ms' => 5000.0]],
        ]));
        file_put_contents($this->dir.'/pending/10-1-bbbbbbbb.json', json_encode([
            'complete' => true,
            'tests' => ['new' => ['file' => 'tests/FooTest.php', 'ms' => 50.0]],
        ]));

        expect($this->store->fileTotals())->toBe(['tests/FooTest.php' => 50.0]);
    });

    it('keeps file totals when a pending batch is merged while it is being read', function () {
        Dirs::ensure($this->dir.'/pending');
        $pending = $this->dir.'/pending/100-1-aabbccdd.json';
        file_put_contents($pending, json_encode([
            'complete' => true,
            'tests' => ['race' => ['file' => 'tests/RaceTest.php', 'ms' => 42.0]],
        ]));

        TimingStorePendingReadRace::enable($this->dir, $pending);

        $totals = $this->store->fileTotals();

        expect(TimingStorePendingReadRace::triggered())->toBeTrue()
            ->and($totals)->toBe(['tests/RaceTest.php' => 42.0])
            ->and(glob($this->dir.'/pending/*.json'))->toBe([])
            ->and(is_file($this->dir.'/timings.json'))->toBeTrue();
    });

    it('does not report a disappeared pending batch as undecodable junk during read', function () {
        Dirs::ensure($this->dir.'/pending');
        $pending = $this->dir.'/pending/100-1-aabbccdd.json';
        file_put_contents($pending, json_encode([
            'complete' => true,
            'tests' => ['race' => ['file' => 'tests/RaceTest.php', 'ms' => 42.0]],
        ]));

        $script = $this->dir.'/load-after-merge-race.php';
        file_put_contents($script, <<<'PHP'
<?php

namespace RawPHP\Warp\Timing {
    function file_get_contents($filename, $use_include_path = false, $context = null, $offset = 0, $length = null): string|false
    {
        if ($filename === $GLOBALS['argv'][2] && empty($GLOBALS['triggered'])) {
            $GLOBALS['triggered'] = true;
            $batch = json_decode((string) \file_get_contents($filename), true);
            \file_put_contents($GLOBALS['argv'][1].'/timings.json', json_encode([
                'version' => 2,
                'tests' => is_array($batch) && is_array($batch['tests'] ?? null) ? $batch['tests'] : [],
            ], JSON_THROW_ON_ERROR));
            \unlink($filename);

            return false;
        }

        if ($length === null) {
            return \file_get_contents($filename, $use_include_path, $context, $offset);
        }

        return \file_get_contents($filename, $use_include_path, $context, $offset, $length);
    }
}

namespace {
    require getcwd().'/vendor/autoload.php';

    $totals = (new RawPHP\Warp\Timing\TimingStore($argv[1]))->fileTotals();
    fwrite(STDOUT, json_encode($totals, JSON_THROW_ON_ERROR));
}
PHP);

        $process = proc_open([PHP_BINARY, $script, $this->dir, $pending], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, getcwd());

        expect($process)->not->toBeFalse();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0)
            ->and(json_decode($stdout, true))->toBe(['tests/RaceTest.php' => 42])
            ->and($stderr)->not->toContain('skipped undecodable pending timings batch');
    });

    it('warns and continues when one merged pending batch cannot be deleted', function () {
        Dirs::ensure($this->dir.'/pending');
        $stuckPath = $this->dir.'/pending/100-0-badbad00.json';
        $deletedPath = $this->dir.'/pending/200-1-aabbccdd.json';
        file_put_contents($stuckPath, json_encode([
            'complete' => true,
            'tests' => ['stuck' => ['file' => 'tests/StuckTest.php', 'ms' => 10.0]],
        ]));
        file_put_contents($deletedPath, json_encode([
            'complete' => true,
            'tests' => ['deleted' => ['file' => 'tests/DeletedTest.php', 'ms' => 20.0]],
        ]));

        $script = $this->dir.'/merge-unlink-failure.php';
        file_put_contents($script, <<<'PHP'
<?php

namespace RawPHP\Warp\Timing {
    function unlink(string $path, $context = null): bool
    {
        if (basename($path) === $GLOBALS['argv'][2]) {
            return false;
        }

        return \unlink($path, $context);
    }
}

namespace {
    require getcwd().'/vendor/autoload.php';

    $count = (new RawPHP\Warp\Timing\TimingStore($argv[1]))->mergeToDisk();
    fwrite(STDOUT, "merged={$count}\n");
}
PHP);

        $process = proc_open([PHP_BINARY, $script, $this->dir, basename($stuckPath)], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, getcwd());

        expect($process)->not->toBeFalse();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0);

        $merged = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

        expect($stdout)->toContain('merged=2')
            ->and($stderr)->toContain('[warp] cannot delete merged pending timings batch')
            ->and($stderr)->toContain(basename($stuckPath))
            ->and($merged['tests'] ?? [])->toHaveKeys(['stuck', 'deleted'])
            ->and(is_file($stuckPath))->toBeTrue()
            ->and(is_file($deletedPath))->toBeFalse();
    });

    it('re-applies a surviving old pending batch before newer pending data on a later merge', function () {
        Dirs::ensure($this->dir.'/pending');
        $stuckPath = $this->dir.'/pending/100-0-badbad00.json';
        file_put_contents($stuckPath, json_encode([
            'complete' => true,
            'tests' => ['old' => ['file' => 'tests/FooTest.php', 'ms' => 5000.0]],
        ]));

        $script = $this->dir.'/merge-unlink-failure.php';
        file_put_contents($script, <<<'PHP'
<?php

namespace RawPHP\Warp\Timing {
    function unlink(string $path, $context = null): bool
    {
        if (basename($path) === $GLOBALS['argv'][2]) {
            return false;
        }

        return \unlink($path, $context);
    }
}

namespace {
    require getcwd().'/vendor/autoload.php';

    $count = (new RawPHP\Warp\Timing\TimingStore($argv[1]))->mergeToDisk();
    fwrite(STDOUT, "merged={$count}\n");
}
PHP);

        $first = proc_open([PHP_BINARY, $script, $this->dir, basename($stuckPath)], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $firstPipes, getcwd());

        expect($first)->not->toBeFalse();

        stream_get_contents($firstPipes[1]);
        stream_get_contents($firstPipes[2]);
        fclose($firstPipes[1]);
        fclose($firstPipes[2]);

        expect(proc_close($first))->toBe(0)
            ->and(is_file($stuckPath))->toBeTrue();

        $newPath = $this->dir.'/pending/200-1-aabbccdd.json';
        file_put_contents($newPath, json_encode([
            'complete' => true,
            'tests' => ['new' => ['file' => 'tests/FooTest.php', 'ms' => 50.0]],
        ]));

        $second = proc_open([PHP_BINARY, $script, $this->dir, basename($stuckPath)], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $secondPipes, getcwd());

        expect($second)->not->toBeFalse();

        $stdout = stream_get_contents($secondPipes[1]);
        $stderr = stream_get_contents($secondPipes[2]);
        fclose($secondPipes[1]);
        fclose($secondPipes[2]);

        expect(proc_close($second))->toBe(0);

        $merged = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

        expect($stdout)->toContain('merged=2')
            ->and($stderr)->toContain(basename($stuckPath))
            ->and($merged['tests'] ?? [])->toHaveKey('new')
            ->and($merged['tests'] ?? [])->not->toHaveKey('old')
            ->and((float) $merged['tests']['new']['ms'])->toBe(50.0)
            ->and(is_file($stuckPath))->toBeTrue()
            ->and(is_file($newPath))->toBeFalse();
    });

    it('deletes corrupt and scalar pending files during merge after warning', function () {
        Dirs::ensure($this->dir.'/pending');
        $badPath = $this->dir.'/pending/100-0-badbad00.json';
        $scalarPath = $this->dir.'/pending/150-0-cafebabe.json';
        file_put_contents($badPath, 'not json');
        file_put_contents($scalarPath, json_encode('not a batch'));
        file_put_contents($this->dir.'/pending/200-1-aabbccdd.json', json_encode([
            'complete' => true,
            'tests' => ['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]],
        ]));

        $script = $this->dir.'/merge.php';
        file_put_contents($script, <<<'PHP'
<?php

require getcwd().'/vendor/autoload.php';

(new RawPHP\Warp\Timing\TimingStore($argv[1]))->mergeToDisk();
PHP);

        $process = proc_open([PHP_BINARY, $script, $this->dir], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, getcwd());

        expect($process)->not->toBeFalse();

        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0);

        $merged = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

        expect($merged['tests'] ?? [])->toHaveKey('t1')
            ->and(is_file($badPath))->toBeFalse()
            ->and(is_file($scalarPath))->toBeFalse()
            ->and($stderr)->toContain('skipped undecodable pending timings batch')
            ->and($stderr)->toContain('100-0-badbad00.json')
            ->and($stderr)->toContain('skipped invalid pending timings batch')
            ->and($stderr)->toContain('150-0-cafebabe.json');
    });

    it('keeps load read-only when pending files are corrupt or scalar', function () {
        Dirs::ensure($this->dir.'/pending');
        $badPath = $this->dir.'/pending/100-0-badbad00.json';
        $scalarPath = $this->dir.'/pending/150-0-cafebabe.json';
        file_put_contents($badPath, 'not json');
        file_put_contents($scalarPath, json_encode('not a batch'));

        $script = $this->dir.'/load-junk.php';
        file_put_contents($script, <<<'PHP'
<?php

require getcwd().'/vendor/autoload.php';

(new RawPHP\Warp\Timing\TimingStore($argv[1]))->load();
PHP);

        $process = proc_open([PHP_BINARY, $script, $this->dir], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, getcwd());

        expect($process)->not->toBeFalse();

        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0);

        expect(is_file($badPath))->toBeTrue()
            ->and(is_file($scalarPath))->toBeTrue()
            ->and(is_file($this->dir.'/timings.json'))->toBeFalse()
            ->and(is_file($this->dir.'/merge.lock'))->toBeFalse()
            ->and($stderr)->toContain('skipped undecodable pending timings batch')
            ->and($stderr)->toContain('100-0-badbad00.json')
            ->and($stderr)->toContain('skipped invalid pending timings batch')
            ->and($stderr)->toContain('150-0-cafebabe.json');
    });

    it('skips old-format pending files with a warning', function () {
        Dirs::ensure($this->dir.'/pending');
        $oldPath = $this->dir.'/pending/12345-aaaaaaaa.json';
        file_put_contents($oldPath, json_encode([
            'complete' => true,
            'tests' => ['old' => ['file' => 'tests/FooTest.php', 'ms' => 5000.0]],
        ]));
        file_put_contents($this->dir.'/pending/200-1-bbbbbbbb.json', json_encode([
            'complete' => true,
            'tests' => ['new' => ['file' => 'tests/FooTest.php', 'ms' => 50.0]],
        ]));

        $script = $this->dir.'/merge-old-format.php';
        file_put_contents($script, <<<'PHP'
<?php

require getcwd().'/vendor/autoload.php';

(new RawPHP\Warp\Timing\TimingStore($argv[1]))->mergeToDisk();
PHP);

        $process = proc_open([PHP_BINARY, $script, $this->dir], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, getcwd());

        expect($process)->not->toBeFalse();

        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0);

        $merged = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

        expect($merged['tests']['new']['file'] ?? null)->toBe('tests/FooTest.php')
            ->and((float) ($merged['tests']['new']['ms'] ?? 0))->toBe(50.0)
            ->and(is_file($oldPath))->toBeTrue()
            ->and($stderr)->toContain('skipped old-format pending timings batch')
            ->and($stderr)->toContain('12345-aaaaaaaa.json');
    });

    it('discovers pending batches when the store path contains glob metacharacters', function () {
        Dirs::delete($this->dir);

        $this->dir = sys_get_temp_dir().'/warp-timings-base[1]-star*-question?/timings';
        $this->store = new TimingStore($this->dir);

        $this->store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);

        expect($this->store->load())->toBe(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);
    });

    it('drops malformed entries from a pending batch', function () {
        Dirs::ensure($this->dir.'/pending');
        file_put_contents($this->dir.'/pending/100-0-aabbccdd.json', json_encode([
            'complete' => true,
            'tests' => [
                'good' => ['file' => 'tests/ATest.php', 'ms' => 5.5],
                'no-file' => ['ms' => 5.5],
                'bad-ms' => ['file' => 'tests/BTest.php', 'ms' => 'slow'],
            ],
        ]));

        expect(array_keys($this->store->load()))->toBe(['good']);
    });

    it('treats a merged file with an unknown version as empty', function () {
        Dirs::ensure($this->dir);
        file_put_contents($this->dir.'/timings.json', json_encode([
            'version' => 99,
            'tests' => ['t9' => ['file' => 'tests/XTest.php', 'ms' => 1.5]],
        ]));

        expect($this->store->load())->toBe([]);
    });

    it('aggregates per-file totals sorted by path', function () {
        $totals = TimingStore::aggregate([
            't1' => ['file' => 'tests/BTest.php', 'ms' => 1.5],
            't2' => ['file' => 'tests/ATest.php', 'ms' => 2.5],
            't3' => ['file' => 'tests/BTest.php', 'ms' => 2.0],
        ]);

        expect($totals)->toBe(['tests/ATest.php' => 2.5, 'tests/BTest.php' => 3.5]);
    });

    it('fileTotals merges then aggregates', function () {
        $this->store->writePending([
            't1' => ['file' => 'tests/ATest.php', 'ms' => 1.5],
            't2' => ['file' => 'tests/ATest.php', 'ms' => 2.0],
        ]);

        expect($this->store->fileTotals())->toBe(['tests/ATest.php' => 3.5]);
    });

    it('stamps the bumped schema version into merged timings', function () {
        $this->store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);
        $this->store->mergeToDisk();

        $merged = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

        expect($merged['version'])->toBe(2);
    });

    it('stamps the canonical root into pending batches, merged timings, and storedRoot', function () {
        $store = (new TimingStore($this->dir))->withRoot('/abs/config/root');
        $store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);

        $pending = glob($this->dir.'/pending/*.json');
        $batch = json_decode((string) file_get_contents($pending[0]), true);

        expect($batch['root'])->toBe('/abs/config/root');

        $store->mergeToDisk();

        $merged = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

        expect($merged['root'])->toBe('/abs/config/root')
            ->and((new TimingStore($this->dir))->storedRoot())->toBe('/abs/config/root');
    });

    it('exposes a stored root from a pending overlay before any merge', function () {
        $store = (new TimingStore($this->dir))->withRoot('/abs/config/root');
        $store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);

        expect((new TimingStore($this->dir))->storedRoot())->toBe('/abs/config/root')
            ->and(is_file($this->dir.'/timings.json'))->toBeFalse();
    });

    it('has no stored root when timings were recorded without one', function () {
        $this->store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]]);
        $this->store->mergeToDisk();

        expect($this->store->storedRoot())->toBeNull();
    });

    it('reports no stored root when nothing was recorded', function () {
        expect($this->store->storedRoot())->toBeNull();
    });

    it('fromEnv honours WARP_TIMINGS_DIR', function () {
        putenv('WARP_TIMINGS_DIR='.$this->dir);

        TimingStore::fromEnv()->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 1.5]]);

        expect(glob($this->dir.'/pending/*.json'))->toHaveCount(1);
    });

    it('fromEnv falls back to a relative timings dir when cwd is unavailable', function () {
        $root = dirname(__DIR__, 3);
        $script = sys_get_temp_dir().'/warp-missing-cwd-'.bin2hex(random_bytes(4)).'.php';

        file_put_contents($script, sprintf(<<<'PHP'
<?php

require %s;

use RawPHP\Warp\Timing\TimingStore;

$cwd = sys_get_temp_dir().'/warp-deleted-cwd-'.bin2hex(random_bytes(4));
mkdir($cwd);
chdir($cwd);
rmdir($cwd);
putenv('WARP_TIMINGS_DIR');
$store = TimingStore::fromEnv();
$dir = (new ReflectionProperty(TimingStore::class, 'dir'))->getValue($store);

if ($dir === '/.warp/timings') {
    fwrite(STDERR, 'unexpected root timings dir');
    exit(2);
}

if ($dir !== './.warp/timings') {
    fwrite(STDERR, 'unexpected timings dir: '.$dir);
    exit(3);
}
PHP,
            var_export($root.'/vendor/autoload.php', true),
        ));

        try {
            exec('php '.escapeshellarg($script).' 2>&1', $output, $exit);

            expect($exit)->toBe(0, implode(PHP_EOL, $output))
                ->and(is_dir('/.warp/timings'))->toBeFalse();
        } finally {
            @unlink($script);
        }
    });

    it('fromEnv absolutizes a relative WARP_TIMINGS_DIR against getcwd()', function () {
        putenv('WARP_TIMINGS_DIR=relative/timings/dir');

        $store = TimingStore::fromEnv();
        $dir = (new ReflectionProperty(TimingStore::class, 'dir'))->getValue($store);

        expect($dir)->toBe((getcwd() ?: '.').'/relative/timings/dir');
    });

    it('fromEnv stores an absolute WARP_TIMINGS_DIR unchanged', function () {
        putenv('WARP_TIMINGS_DIR='.$this->dir);

        $store = TimingStore::fromEnv();
        $dir = (new ReflectionProperty(TimingStore::class, 'dir'))->getValue($store);

        expect($dir)->toBe($this->dir);
    });

    it('reproduces the original bug: fromEnv with a relative dir still writes under the original cwd after a later chdir (regression, fails pre-fix)', function () {
        $root = dirname(__DIR__, 3);
        $projectDir = sys_get_temp_dir().'/warp-req094-project-'.bin2hex(random_bytes(4));
        $elsewhere = sys_get_temp_dir().'/warp-req094-elsewhere-'.bin2hex(random_bytes(4));
        mkdir($projectDir);
        mkdir($elsewhere);

        $script = sys_get_temp_dir().'/warp-req094-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($script, sprintf(<<<'PHP'
<?php

require %s;

use RawPHP\Warp\Timing\TimingStore;

chdir(%s);
putenv('WARP_TIMINGS_DIR=.warp/timings');
$store = TimingStore::fromEnv();

// Simulate a chdir that survives past test execution (tearDownAfterClass,
// bootstrap, another shutdown handler, or a fatal that skips PHPUnit's
// runBare cwd restore) before the shutdown-flush backstop fires.
chdir(%s);

$store->writePending(['t1' => ['file' => 'tests/ATest.php', 'ms' => 1.5]]);
PHP,
            var_export($root.'/vendor/autoload.php', true),
            var_export($projectDir, true),
            var_export($elsewhere, true),
        ));

        try {
            exec('php '.escapeshellarg($script).' 2>&1', $output, $exit);

            expect($exit)->toBe(0, implode(PHP_EOL, $output))
                ->and(glob($projectDir.'/.warp/timings/pending/*.json'))->toHaveCount(1)
                ->and(is_dir($elsewhere.'/.warp/timings'))->toBeFalse();
        } finally {
            foreach (glob($projectDir.'/.warp/timings/pending/*.json') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($projectDir.'/.warp/timings/pending');
            @rmdir($projectDir.'/.warp/timings');
            @rmdir($projectDir);
            @rmdir($elsewhere);
            @unlink($script);
        }
    });
}
