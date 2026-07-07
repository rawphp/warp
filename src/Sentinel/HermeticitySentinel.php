<?php

declare(strict_types=1);

namespace RawPHP\Warp\Sentinel;

use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;

final class HermeticitySentinel
{
    /** Warp's own control variables are orchestration state, not app env. */
    private const IGNORED_PREFIX = 'WARP_';

    /**
     * Terminal-dimension variables set by Symfony Console when it probes the
     * TTY (e.g. the first `migrate:fresh` an app's RefreshDatabase runs). They
     * are terminal metadata, not application state, so they must not be
     * attributed to the test that happened to trigger the probe.
     */
    private const IGNORED_KEYS = ['LINES', 'COLUMNS'];

    /**
     * @param  array<string, string>  $env
     * @param  array<string, string>  $staticFingerprints
     * @param  array<string, callable(): string>  $staticProbes
     */
    private function __construct(
        private readonly array $env,
        private readonly string $configHash,
        private readonly array $staticFingerprints,
        private readonly array $staticProbes,
    ) {}

    /** @param array<string, callable(): string> $staticProbes */
    public static function capture(Application $base, array $staticProbes = []): self
    {
        $probes = $staticProbes + self::defaultProbes();

        return new self(
            self::currentEnv(),
            self::hashConfig($base),
            array_map(fn (callable $probe): string => $probe(), $probes),
            $probes,
        );
    }

    /** Diff against pristine; restores leaked env vars so neighbours are not poisoned. */
    public function check(Application $base): LeakReport
    {
        $leaks = [];
        $now = self::currentEnv();

        foreach ($now as $key => $value) {
            if (! array_key_exists($key, $this->env)) {
                $leaks[] = "env var added: {$key}='{$value}'";
            } elseif ($this->env[$key] !== $value) {
                $leaks[] = "env var changed: {$key} '{$this->env[$key]}' -> '{$value}'";
            }
        }

        foreach ($this->env as $key => $value) {
            if (! array_key_exists($key, $now)) {
                $leaks[] = "env var removed: {$key} (was '{$value}')";
            }
        }

        if ($leaks !== []) {
            $this->restoreEnv($now);
        }

        $baseCorrupted = self::hashConfig($base) !== $this->configHash;

        if ($baseCorrupted) {
            $leaks[] = 'base config mutated through a shared reference (warm base scrapped; next test reboots pristine)';
        }

        foreach ($this->staticProbes as $name => $probe) {
            $current = $probe();

            if ($current !== $this->staticFingerprints[$name]) {
                $added = implode(', ', array_diff(
                    explode('|', $current),
                    explode('|', $this->staticFingerprints[$name]),
                ));

                $leaks[] = "static state leaked: {$name}".($added !== '' ? " (added: {$added})" : '');
            }
        }

        return new LeakReport($leaks, $baseCorrupted);
    }

    /** @return array<string, string> */
    private static function currentEnv(): array
    {
        return array_filter(
            getenv(),
            fn (string $key): bool => ! str_starts_with($key, self::IGNORED_PREFIX)
                && ! in_array($key, self::IGNORED_KEYS, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @param array<string, string> $now */
    private function restoreEnv(array $now): void
    {
        foreach ($now as $key => $value) {
            if (! array_key_exists($key, $this->env)) {
                putenv($key);
                unset($_ENV[$key]);
            }
        }

        foreach ($this->env as $key => $value) {
            if (($now[$key] ?? null) !== $value) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    private static function hashConfig(Application $base): string
    {
        return hash('xxh128', (string) json_encode(
            $base->make('config')->all(),
            JSON_PARTIAL_OUTPUT_ON_ERROR,
        ));
    }

    /** @return array<string, callable(): string> */
    private static function defaultProbes(): array
    {
        return [
            'carbon.testNow' => fn (): string => Carbon::hasTestNow() ? '1' : '0',
        ];
    }
}
