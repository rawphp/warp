<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

final class TimingCollector
{
    /** @var array<string, float> */
    private array $startedAt = [];

    /** @var array<string, array{file: string, ms: float}> */
    private array $tests = [];

    private int $unattributed = 0;

    private bool $flushed = false;

    public function started(string $id, float $seconds): void
    {
        $this->startedAt[$id] = $seconds;
    }

    public function finished(string $id, ?string $file, float $seconds): void
    {
        $start = $this->startedAt[$id] ?? null;
        unset($this->startedAt[$id]);

        if ($start === null) {
            return;
        }

        if ($file === null) {
            $this->unattributed++;

            return;
        }

        $this->tests[$id] = ['file' => $file, 'ms' => round(($seconds - $start) * 1000, 3)];
    }

    /** @return array<string, array{file: string, ms: float}> */
    public function all(): array
    {
        return $this->tests;
    }

    public function unattributedCount(): int
    {
        return $this->unattributed;
    }

    public function hasFlushed(): bool
    {
        return $this->flushed;
    }

    public function hasInFlight(): bool
    {
        return $this->startedAt !== [];
    }

    /** Idempotent: the ExecutionFinished subscriber and the shutdown backstop may both call this. */
    public function flush(TimingStore $store, bool $complete = true): void
    {
        if ($this->flushed) {
            return;
        }

        $store->writePending($this->tests, $complete);
        $this->flushed = true;
    }
}
