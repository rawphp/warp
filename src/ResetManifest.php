<?php

declare(strict_types=1);

namespace RawPHP\Warp;

use Closure;
use Illuminate\Foundation\Application;
use RawPHP\Warp\Support\ObjectAccess;
use RawPHP\Warp\Warm\DefaultResetSteps;

/**
 * Declarative reset steps applied to every fresh sandbox. A shallow clone
 * copies container arrays, so only services resolved during BOOT (present in
 * the base's instances array) are shared between tests and need handling here.
 */
final class ResetManifest
{
    /** @var list<string> */
    private array $forget = [];

    /** @var list<array{string, string}> */
    private array $repoint = [];

    /** @var list<array{string, string}> */
    private array $flush = [];

    /** @var list<Closure(Application, Application): void> */
    private array $custom = [];

    /**
     * Laravel-default sandbox resets. Host apps extend via
     * {@see Concerns\InteractsWithWarmApplication::warpResetManifest()}.
     *
     * Construction is owned by {@see DefaultResetSteps::manifest()}; this is a
     * thin public entry so hosts keep chaining from ResetManifest::default().
     */
    public static function default(): self
    {
        return DefaultResetSteps::manifest();
    }

    public function forget(string ...$ids): self
    {
        foreach ($ids as $id) {
            $this->forget[] = $id;
        }

        return $this;
    }

    public function repoint(string $id, string $property): self
    {
        $this->repoint[] = [$id, $property];

        return $this;
    }

    public function flush(string $id, string $method): self
    {
        $this->flush[] = [$id, $method];

        return $this;
    }

    /** @param Closure(Application, Application): void $step */
    public function add(Closure $step): self
    {
        $this->custom[] = $step;

        return $this;
    }

    public function apply(Application $sandbox, Application $base): void
    {
        $sandbox->forgetScopedInstances();

        foreach ($this->forget as $id) {
            $sandbox->forgetInstance($id);
        }

        foreach ($this->repoint as [$id, $property]) {
            if (! $sandbox->resolved($id)) {
                continue;
            }

            ObjectAccess::set($sandbox->make($id), $property, $sandbox);
        }

        foreach ($this->flush as [$id, $method]) {
            if ($sandbox->resolved($id)) {
                $sandbox->make($id)->{$method}();
            }
        }

        foreach ($this->custom as $step) {
            $step($sandbox, $base);
        }
    }
}
