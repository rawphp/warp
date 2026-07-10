<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing {
    if (! function_exists(__NAMESPACE__.'\\file_get_contents')) {
        function file_get_contents($filename, $use_include_path = false, $context = null, $offset = 0, $length = null): string|false
        {
            \PendingReadCounter::trackIfPending($filename);

            if (\TimingStorePendingReadRace::enabledFor($filename)) {
                return \TimingStorePendingReadRace::read($filename);
            }

            if ($length === null) {
                return \file_get_contents($filename, $use_include_path, $context, $offset);
            }

            return \file_get_contents($filename, $use_include_path, $context, $offset, $length);
        }
    }

    if (! function_exists(__NAMESPACE__.'\\scandir')) {
        function scandir($directory, $sorting_order = SCANDIR_SORT_ASCENDING, $context = null)
        {
            \PendingScanCounter::trackIfPending($directory);

            return \scandir($directory, $sorting_order, $context);
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
                    'version' => 3,
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

    // Spies for REQ-104 (finding 17): count how many times pending/ is scanned
    // and how many pending batch files are read, so a single command-scoped
    // read (storedRoot() + fileTotals() sharing one snapshot) is provably one
    // scandir() and one file_get_contents() per batch - not two independent
    // passes. Guarded by enable()/disable() so other tests are unaffected.
    if (! class_exists(PendingScanCounter::class, false)) {
        final class PendingScanCounter
        {
            private static bool $enabled = false;

            private static int $count = 0;

            public static function enable(): void
            {
                self::$enabled = true;
                self::$count = 0;
            }

            public static function disable(): void
            {
                self::$enabled = false;
            }

            public static function trackIfPending(string $directory): void
            {
                if (self::$enabled && str_ends_with($directory, '/pending')) {
                    self::$count++;
                }
            }

            public static function count(): int
            {
                return self::$count;
            }
        }
    }

    if (! class_exists(PendingReadCounter::class, false)) {
        final class PendingReadCounter
        {
            private static bool $enabled = false;

            private static int $count = 0;

            public static function enable(): void
            {
                self::$enabled = true;
                self::$count = 0;
            }

            public static function disable(): void
            {
                self::$enabled = false;
            }

            public static function trackIfPending(string $path): void
            {
                if (self::$enabled && str_contains($path, '/pending/') && str_ends_with($path, '.json')) {
                    self::$count++;
                }
            }

            public static function count(): int
            {
                return self::$count;
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
        PendingScanCounter::disable();
        PendingReadCounter::disable();
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

        // A writable timings dir means the read snapshot holds merge.lock
        // (REQ-104, finding 2), so the lock file now exists after load() -
        // pending batches themselves are still left untouched (read-only).
        expect($tests)->toBe(['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.5]])
            ->and(glob($this->dir.'/pending/*.json'))->toHaveCount(1)
            ->and(is_file($this->dir.'/merge.lock'))->toBeTrue()
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

    it('routes store warnings through an injectable sink so no CLI-reachable path writes raw STDERR', function () {
        $storeSource = (string) file_get_contents(dirname(__DIR__, 3).'/src/Timing/TimingStore.php');

        expect(class_exists(Stderr::class))->toBeTrue()
            // Every CLI-reachable warning emission point routes through the injected sink...
            ->and(substr_count($storeSource, '$this->warn('))->toBeGreaterThan(0)
            // ...and the sole direct Stderr::write left is the extension/embedded default fallback.
            ->and(substr_count($storeSource, 'Stderr::write'))->toBe(1);
    });

    it('loads from a read-only directory with pending batches without writing a lock or clearing pending', function () {
        Dirs::ensure($this->dir.'/pending');

        file_put_contents($this->dir.'/timings.json', json_encode([
            'version' => 3,
            'tests' => [
                'old' => ['file' => 'tests/OldTest.php', 'ms' => 100.0],
                'stale' => ['file' => 'tests/FooTest.php', 'ms' => 5000.0],
            ],
        ]));

        file_put_contents($this->dir.'/pending/100-1-aabbccdd.json', json_encode([
            'complete' => ['tests/FooTest.php' => true],
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
                'complete' => [],
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
            'version' => 3,
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

        // t2 was renamed/deleted since: the fresh, complete run of ATest.php only
        // has t1, so it supersedes ATest.php while leaving BTest.php untouched.
        $this->store->writePending(
            ['t1' => ['file' => 'tests/ATest.php', 'ms' => 11.5]],
            ['tests/ATest.php' => true],
        );

        $tests = $this->store->load();

        expect($tests)->toHaveKeys(['t1', 't3'])
            ->and($tests)->not->toHaveKey('t2')
            ->and($tests['t1']['ms'])->toBe(11.5)
            ->and($tests['t3']['ms'])->toBe(30.5);
    });

    it('incomplete pending batches merge by test id without superseding a whole file', function () {
        Dirs::ensure($this->dir);
        file_put_contents($this->dir.'/timings.json', json_encode([
            'version' => 3,
            'tests' => [
                'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
                'FileA::two' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
                'FileA::three' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
            ],
        ]));

        Dirs::ensure($this->dir.'/pending');
        file_put_contents($this->dir.'/pending/100-1-aabbccdd.json', json_encode([
            'complete' => ['tests/FileATest.php' => false],
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

    it('merges two incomplete slices of one file to the union without mutual deletion (finding 14)', function () {
        Dirs::ensure($this->dir.'/pending');

        // Two paratest --functional workers: each enumerated FooTest.php fully but
        // ran only half its methods, so each batch flags the file incomplete and
        // upserts its own ids. Neither may delete the other's half.
        file_put_contents($this->dir.'/pending/100-1-aaaaaaaa.json', json_encode([
            'complete' => ['tests/FooTest.php' => false],
            'tests' => [
                'Foo::a' => ['file' => 'tests/FooTest.php', 'ms' => 10.0],
                'Foo::b' => ['file' => 'tests/FooTest.php', 'ms' => 20.0],
            ],
        ]));
        file_put_contents($this->dir.'/pending/200-1-bbbbbbbb.json', json_encode([
            'complete' => ['tests/FooTest.php' => false],
            'tests' => [
                'Foo::c' => ['file' => 'tests/FooTest.php', 'ms' => 30.0],
                'Foo::d' => ['file' => 'tests/FooTest.php', 'ms' => 40.0],
            ],
        ]));

        $this->store->mergeToDisk();

        expect(array_keys($this->store->load()))->toBe(['Foo::a', 'Foo::b', 'Foo::c', 'Foo::d'])
            ->and($this->store->fileTotals())->toBe(['tests/FooTest.php' => 100.0]);
    });

    it('complete pending batches keep superseding all previous entries for covered files', function () {
        Dirs::ensure($this->dir);
        file_put_contents($this->dir.'/timings.json', json_encode([
            'version' => 3,
            'tests' => [
                'FileA::one' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
                'FileA::two' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
                'FileA::three' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
            ],
        ]));

        Dirs::ensure($this->dir.'/pending');
        file_put_contents($this->dir.'/pending/100-1-aabbccdd.json', json_encode([
            'complete' => ['tests/FileATest.php' => true],
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
            'version' => 3,
            'tests' => [
                'FileA::old' => ['file' => 'tests/FileATest.php', 'ms' => 1000.0],
                'FileB::old' => ['file' => 'tests/FileBTest.php', 'ms' => 2000.0],
            ],
        ];
        $oldBatch = [
            'complete' => ['tests/FileATest.php' => true],
            'tests' => [
                'FileA::fresh' => ['file' => 'tests/FileATest.php', 'ms' => 10.0],
            ],
        ];
        $partialBatch = [
            'complete' => ['tests/FileBTest.php' => false],
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
            'complete' => ['tests/FooTest.php' => true],
            'tests' => ['old' => ['file' => 'tests/FooTest.php', 'ms' => 5000.0]],
        ]));
        file_put_contents($this->dir.'/pending/200-1-bbbbbbbb.json', json_encode([
            'complete' => ['tests/FooTest.php' => true],
            'tests' => ['new' => ['file' => 'tests/FooTest.php', 'ms' => 50.0]],
        ]));

        expect($this->store->fileTotals())->toBe(['tests/FooTest.php' => 50.0]);
    });

    it('merges pending batches by numeric timestamp when older filename sorts after newer lexicographically', function () {
        Dirs::ensure($this->dir.'/pending');
        file_put_contents($this->dir.'/pending/9-99999-aaaaaaaa.json', json_encode([
            'complete' => ['tests/FooTest.php' => true],
            'tests' => ['old' => ['file' => 'tests/FooTest.php', 'ms' => 5000.0]],
        ]));
        file_put_contents($this->dir.'/pending/10-1-bbbbbbbb.json', json_encode([
            'complete' => ['tests/FooTest.php' => true],
            'tests' => ['new' => ['file' => 'tests/FooTest.php', 'ms' => 50.0]],
        ]));

        expect($this->store->fileTotals())->toBe(['tests/FooTest.php' => 50.0]);
    });

    it('skips a pending batch that vanishes mid-read on load without resurrecting it via reset', function () {
        Dirs::ensure($this->dir.'/pending');
        $pending = $this->dir.'/pending/100-1-aabbccdd.json';
        file_put_contents($pending, json_encode([
            'complete' => true,
            'tests' => ['race' => ['file' => 'tests/RaceTest.php', 'ms' => 42.0]],
        ]));

        TimingStorePendingReadRace::enable($this->dir, $pending);

        // load() is read-only and never resets: a batch that disappears mid-read is
        // skipped-and-warned, not resurrected by re-reading merged data. Its contribution
        // is absent from this load but survives on disk (timings.json) for the next one.
        $totals = $this->store->fileTotals();

        expect(TimingStorePendingReadRace::triggered())->toBeTrue()
            ->and($totals)->toBe([])
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
                'version' => 3,
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
            ->and(json_decode($stdout, true))->toBe([])
            ->and($stderr)->not->toContain('skipped undecodable pending timings batch')
            ->and($stderr)->toContain('skipped vanished pending timings batch');
    });

    it('keeps an unreadable pending batch on disk during merge and warns without corrupting timings', function () {
        Dirs::ensure($this->dir.'/pending');
        $unreadable = $this->dir.'/pending/100-1-aabbccdd.json';
        file_put_contents($unreadable, json_encode([
            'complete' => true,
            'tests' => ['u' => ['file' => 'tests/UnreadableTest.php', 'ms' => 99.0]],
        ]));
        file_put_contents($this->dir.'/pending/200-1-bbbbbbbb.json', json_encode([
            'complete' => true,
            'tests' => ['ok' => ['file' => 'tests/OkTest.php', 'ms' => 10.0]],
        ]));

        $script = $this->dir.'/merge-unreadable.php';
        file_put_contents($script, <<<'PHP'
<?php

namespace RawPHP\Warp\Timing {
    function file_get_contents($filename, $use_include_path = false, $context = null, $offset = 0, $length = null): string|false
    {
        if ($filename === $GLOBALS['argv'][2]) {
            return false; // EACCES: file exists but is unreadable; it is NOT unlinked
        }

        if ($length === null) {
            return \file_get_contents($filename, $use_include_path, $context, $offset);
        }

        return \file_get_contents($filename, $use_include_path, $context, $offset, $length);
    }
}

namespace {
    require getcwd().'/vendor/autoload.php';

    (new RawPHP\Warp\Timing\TimingStore($argv[1]))->mergeToDisk();
}
PHP);

        $process = proc_open([PHP_BINARY, $script, $this->dir, $unreadable], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, getcwd());

        expect($process)->not->toBeFalse();

        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0);

        $merged = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

        expect(is_file($unreadable))->toBeTrue()
            ->and($merged['tests'] ?? [])->toHaveKey('ok')
            ->and($merged['tests'] ?? [])->not->toHaveKey('u')
            ->and($stderr)->toContain('skipped unreadable pending timings batch')
            ->and($stderr)->toContain('100-1-aabbccdd.json')
            ->and($stderr)->not->toContain('skipped undecodable pending timings batch');
    });

    it('does not discard already-applied batches when a pending batch vanishes mid-merge', function () {
        Dirs::ensure($this->dir.'/pending');
        $b1 = $this->dir.'/pending/100-1-aabbccdd.json';
        $b2 = $this->dir.'/pending/200-1-bbbbbbbb.json';
        file_put_contents($b1, json_encode([
            'complete' => true,
            'tests' => ['b1' => ['file' => 'tests/B1Test.php', 'ms' => 11.0]],
        ]));
        file_put_contents($b2, json_encode([
            'complete' => true,
            'tests' => ['b2' => ['file' => 'tests/B2Test.php', 'ms' => 22.0]],
        ]));

        $script = $this->dir.'/merge-vanish.php';
        file_put_contents($script, <<<'PHP'
<?php

namespace RawPHP\Warp\Timing {
    function file_get_contents($filename, $use_include_path = false, $context = null, $offset = 0, $length = null): string|false
    {
        if ($filename === $GLOBALS['argv'][2]) {
            \unlink($filename); // vanished externally between glob and read
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

    (new RawPHP\Warp\Timing\TimingStore($argv[1]))->mergeToDisk();
}
PHP);

        $process = proc_open([PHP_BINARY, $script, $this->dir, $b2], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, getcwd());

        expect($process)->not->toBeFalse();

        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0);

        $merged = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

        expect($merged['tests'] ?? [])->toHaveKey('b1')
            ->and(is_file($b1))->toBeFalse()
            ->and(is_file($b2))->toBeFalse()
            ->and($stderr)->toContain('skipped vanished pending timings batch')
            ->and($stderr)->toContain('200-1-bbbbbbbb.json');
    });

    it('skips only the unreadable batch on load and keeps earlier applied batches', function () {
        Dirs::ensure($this->dir.'/pending');
        $a = $this->dir.'/pending/100-1-aaaaaaaa.json';
        $b = $this->dir.'/pending/200-1-bbbbbbbb.json';
        $c = $this->dir.'/pending/300-1-cccccccc.json';
        file_put_contents($a, json_encode([
            'complete' => true,
            'tests' => ['a' => ['file' => 'tests/ATest.php', 'ms' => 1.0]],
        ]));
        file_put_contents($b, json_encode([
            'complete' => true,
            'tests' => ['b' => ['file' => 'tests/BTest.php', 'ms' => 2.0]],
        ]));
        file_put_contents($c, json_encode([
            'complete' => true,
            'tests' => ['c' => ['file' => 'tests/CTest.php', 'ms' => 3.0]],
        ]));

        $script = $this->dir.'/load-unreadable.php';
        file_put_contents($script, <<<'PHP'
<?php

namespace RawPHP\Warp\Timing {
    function file_get_contents($filename, $use_include_path = false, $context = null, $offset = 0, $length = null): string|false
    {
        if ($filename === $GLOBALS['argv'][2]) {
            return false; // EACCES: unreadable, stays on disk (load is read-only)
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

        $process = proc_open([PHP_BINARY, $script, $this->dir, $c], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, getcwd());

        expect($process)->not->toBeFalse();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0);

        $totals = json_decode($stdout, true);

        expect($totals)->toHaveKey('tests/ATest.php')
            ->and($totals)->toHaveKey('tests/BTest.php')
            ->and($totals)->not->toHaveKey('tests/CTest.php')
            ->and(is_file($c))->toBeTrue()
            ->and($stderr)->toContain('skipped unreadable pending timings batch')
            ->and($stderr)->toContain('300-1-cccccccc.json');
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
            'complete' => ['tests/FooTest.php' => true],
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
            'complete' => ['tests/FooTest.php' => true],
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

        // Writable dir: the read snapshot holds merge.lock (REQ-104), so the
        // lock file now exists - the pending junk files remain untouched
        // (load() stays read-only regardless of the lock).
        expect(is_file($badPath))->toBeTrue()
            ->and(is_file($scalarPath))->toBeTrue()
            ->and(is_file($this->dir.'/timings.json'))->toBeFalse()
            ->and(is_file($this->dir.'/merge.lock'))->toBeTrue()
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

    it('degrades an undecodable merged timings file to empty without throwing', function () {
        Dirs::ensure($this->dir);
        file_put_contents($this->dir.'/timings.json', 'not json');

        expect($this->store->load())->toBe([]);
    });

    it('rejects a non-finite ms entry during sanitization so merges never wedge on Infinity', function () {
        Dirs::ensure($this->dir.'/pending');
        file_put_contents($this->dir.'/pending/100-1-aabbccdd.json', json_encode([
            'complete' => true,
            'tests' => [
                'poison' => ['file' => 'tests/PoisonTest.php', 'ms' => '1e999'],
                'ok' => ['file' => 'tests/OkTest.php', 'ms' => 12.0],
            ],
        ]));

        expect(array_keys($this->store->load()))->toBe(['ok'])
            ->and($this->store->mergeToDisk())->toBe(1)
            ->and(glob($this->dir.'/pending/*.json'))->toBe([]);

        $merged = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

        expect(array_keys($merged['tests'] ?? []))->toBe(['ok'])
            ->and($merged['tests']['ok']['file'] ?? null)->toBe('tests/OkTest.php');
    });

    it('drops a non-finite ms entry when reading merged timings', function () {
        Dirs::ensure($this->dir);
        file_put_contents($this->dir.'/timings.json', '{"version":3,"tests":{"poison":{"file":"tests/PoisonTest.php","ms":1e999},"ok":{"file":"tests/OkTest.php","ms":9.0}}}');

        expect(array_keys($this->store->load()))->toBe(['ok']);
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

        expect($merged['version'])->toBe(3);
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

    it('keeps the artifact root and warn-deletes a foreign-root pending batch during merge (finding 3)', function () {
        Dirs::ensure($this->dir.'/pending');

        // The existing artifact establishes the authoritative root.
        file_put_contents($this->dir.'/timings.json', json_encode([
            'version' => 3,
            'root' => '/home/project/config',
            'tests' => ['home::a' => ['file' => 'tests/ATest.php', 'ms' => 100.0]],
        ]));

        // A matching-root batch folds in; a foreign-root batch (a stray recording
        // from a different config dir) must not flip the stored root or mix domains.
        file_put_contents($this->dir.'/pending/100-1-aaaaaaaa.json', json_encode([
            'root' => '/home/project/config',
            'tests' => ['home::b' => ['file' => 'tests/BTest.php', 'ms' => 20.0]],
        ]));
        $foreign = $this->dir.'/pending/200-1-bbbbbbbb.json';
        file_put_contents($foreign, json_encode([
            'root' => '/ci/cache/other',
            'tests' => ['foreign::x' => ['file' => 'tests/XTest.php', 'ms' => 999.0]],
        ]));

        $store = new TimingStore($this->dir);
        $store->mergeToDisk();

        $merged = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

        expect($merged['root'])->toBe('/home/project/config')
            ->and(array_keys($merged['tests']))->toBe(['home::a', 'home::b'])
            ->and($merged['tests'])->not->toHaveKey('foreign::x')
            ->and(is_file($foreign))->toBeFalse()
            ->and($store->storedRoot())->toBe('/home/project/config');
    });

    it('skips and warns a foreign-root pending batch on load without deleting it (finding 3)', function () {
        Dirs::ensure($this->dir.'/pending');

        file_put_contents($this->dir.'/timings.json', json_encode([
            'version' => 3,
            'root' => '/home/project/config',
            'tests' => ['home::a' => ['file' => 'tests/ATest.php', 'ms' => 100.0]],
        ]));
        $foreign = $this->dir.'/pending/200-1-bbbbbbbb.json';
        file_put_contents($foreign, json_encode([
            'root' => '/ci/cache/other',
            'tests' => ['foreign::x' => ['file' => 'tests/XTest.php', 'ms' => 999.0]],
        ]));

        $warnings = [];
        $store = (new TimingStore($this->dir))->withWarner(function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        });

        $tests = $store->load();

        expect(array_keys($tests))->toBe(['home::a'])
            ->and($tests)->not->toHaveKey('foreign::x')
            ->and($store->storedRoot())->toBe('/home/project/config')
            ->and(is_file($foreign))->toBeTrue()
            ->and(implode('', $warnings))->toContain('different root')
            ->and(implode('', $warnings))->toContain('/ci/cache/other');
    });

    it('adopts the first pending batch root as authoritative when no artifact exists yet (finding 3)', function () {
        Dirs::ensure($this->dir.'/pending');

        file_put_contents($this->dir.'/pending/100-1-aaaaaaaa.json', json_encode([
            'root' => '/first/root',
            'tests' => ['a' => ['file' => 'tests/ATest.php', 'ms' => 10.0]],
        ]));
        file_put_contents($this->dir.'/pending/200-1-bbbbbbbb.json', json_encode([
            'root' => '/second/root',
            'tests' => ['b' => ['file' => 'tests/BTest.php', 'ms' => 20.0]],
        ]));

        $store = new TimingStore($this->dir);
        $store->mergeToDisk();

        $merged = json_decode((string) file_get_contents($this->dir.'/timings.json'), true);

        expect($merged['root'])->toBe('/first/root')
            ->and(array_keys($merged['tests']))->toBe(['a'])
            ->and($merged['tests'])->not->toHaveKey('b');
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

    it('scans pending/ and parses each batch exactly once when storedRoot and fileTotals share one snapshot (finding 17)', function () {
        Dirs::ensure($this->dir.'/pending');
        file_put_contents($this->dir.'/pending/100-1-aaaaaaaa.json', json_encode([
            'root' => '/config/root',
            'tests' => ['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.0]],
        ]));

        $store = new TimingStore($this->dir);

        PendingScanCounter::enable();
        PendingReadCounter::enable();

        // Mirrors ShardCommand: one store instance, storedRoot() then
        // fileTotals() within a single command invocation. Pre-fix, each
        // call independently rescans pending/ and re-parses every batch
        // (scandir x2, file_get_contents x2) and could observe two different
        // store states (the TOCTOU half of finding 17/finding 2). Post-fix,
        // both consume one memoized snapshot: exactly one scan, one parse.
        $root = $store->storedRoot();
        $totals = $store->fileTotals();

        expect($root)->toBe('/config/root')
            ->and($totals)->toBe(['tests/ATest.php' => 10.0])
            ->and(PendingScanCounter::count())->toBe(1)
            ->and(PendingReadCounter::count())->toBe(1);
    });

    it('waits for a concurrent merge holding merge.lock before producing a snapshot (finding 2)', function () {
        Dirs::ensure($this->dir.'/pending');
        file_put_contents($this->dir.'/pending/100-1-aaaaaaaa.json', json_encode([
            'tests' => ['t1' => ['file' => 'tests/ATest.php', 'ms' => 10.0]],
        ]));

        $holdScript = $this->dir.'/hold-merge-lock.php';
        file_put_contents($holdScript, <<<'PHP'
<?php

$dir = $argv[1];
$handle = fopen($dir.'/merge.lock', 'c');
flock($handle, LOCK_EX);
fwrite(STDOUT, "locked\n");
fflush(STDOUT);
usleep(400000);
flock($handle, LOCK_UN);
fclose($handle);
PHP);

        $process = proc_open([PHP_BINARY, $holdScript, $this->dir], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, getcwd());

        expect($process)->not->toBeFalse();

        // Block until the child confirms it actually holds the lock before
        // racing it - otherwise this test would be timing-dependent on
        // process startup rather than on lock contention.
        expect(trim((string) fgets($pipes[1])))->toBe('locked');

        $start = microtime(true);
        $totals = $this->store->fileTotals();
        $elapsed = microtime(true) - $start;

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // Pre-fix (no lock on the read path) this returns near-instantly
        // while the child still holds merge.lock. Post-fix, the snapshot
        // read blocks on flock(LOCK_EX) until the simulated merge releases
        // it, so the elapsed time must span (most of) the held duration.
        expect($elapsed)->toBeGreaterThan(0.3)
            ->and($totals)->toBe(['tests/ATest.php' => 10.0]);
    });
}
