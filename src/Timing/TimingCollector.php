<?php

declare(strict_types=1);

namespace RawPHP\Warp\Timing;

final class TimingCollector
{
    /** @var array<string, float> */
    private array $startedAt = [];

    /** @var array<string, array{file: string, ms: float}> */
    private array $tests = [];

    /** @var array<string, string> id => resolved file key */
    private array $fileById = [];

    /** @var array<string, array<string, true>> file => enumerated test ids */
    private array $enumeratedByFile = [];

    /** @var array<string, true> ids that reached a terminal event */
    private array $terminatedIds = [];

    private int $unattributed = 0;

    private bool $flushed = false;

    /**
     * Enumerate a test under its file without terminating it. Fed from
     * TestSuite\Loaded (the full, pre-filter suite) so a worker that runs only
     * a slice of a file still knows the file's other tests exist and therefore
     * never marks that file complete.
     */
    public function enumerated(string $id, ?string $file): void
    {
        $this->enumerate($id, $file);
    }

    public function started(string $id, float $seconds, ?string $file = null): void
    {
        $this->startedAt[$id] = $seconds;
        $this->enumerate($id, $file);
    }

    public function finished(string $id, ?string $file, float $seconds): void
    {
        $this->markTerminated($id, $file);

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

    /**
     * Terminate a test's completeness accounting without recording a duration.
     * Fed from Skipped/Errored/MarkedIncomplete (and Finished for non-method
     * tests such as .phpt), which close entries that never emit Test\Finished.
     */
    public function terminated(string $id, ?string $file = null): void
    {
        $this->markTerminated($id, $file);
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

    /**
     * Per-file completeness: a file is complete when every test enumerated for
     * it in this process reached a terminal event.
     *
     * @return array<string, bool>
     */
    public function completeFiles(): array
    {
        $complete = [];

        foreach ($this->enumeratedByFile as $file => $ids) {
            $allTerminated = true;

            foreach (array_keys($ids) as $id) {
                if (! isset($this->terminatedIds[$id])) {
                    $allTerminated = false;

                    break;
                }
            }

            $complete[$file] = $allTerminated;
        }

        return $complete;
    }

    /**
     * Idempotent: the ExecutionFinished subscriber and the shutdown backstop may
     * both call this. The flag is set only after writePending() returns without
     * throwing, so a transient write failure leaves hasFlushed() false and a
     * later backstop retry can still publish the run's timings.
     */
    public function flush(TimingStore $store): void
    {
        if ($this->flushed) {
            return;
        }

        $store->writePending($this->tests, $this->completeFiles());

        $this->flushed = true;
    }

    private function enumerate(string $id, ?string $file): void
    {
        if ($file === null) {
            return;
        }

        $this->fileById[$id] = $file;
        $this->enumeratedByFile[$file][$id] = true;
    }

    private function markTerminated(string $id, ?string $file): void
    {
        $this->enumerate($id, $file ?? ($this->fileById[$id] ?? null));
        $this->terminatedIds[$id] = true;
    }
}
