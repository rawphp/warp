<?php

declare(strict_types=1);

namespace RawPHP\Warp\Sentinel;

final class LeakReport
{
    /** @param list<string> $leaks */
    public function __construct(
        public readonly array $leaks,
        public readonly bool $baseCorrupted,
    ) {}

    public function clean(): bool
    {
        return $this->leaks === [];
    }

    public function describe(): string
    {
        return implode('; ', $this->leaks);
    }
}
