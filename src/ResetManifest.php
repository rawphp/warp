<?php

declare(strict_types=1);

namespace Warp;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;

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

    public static function default(): self
    {
        return (new self)
            // Stateful leaf services: re-resolve fresh per sandbox.
            ->forget(
                'cache', 'cache.store', 'cookie', 'redirect',
                'session', 'session.store', 'url', 'view',
            )
            // Shared boot singletons referenced by other boot-time objects:
            // keep the object, point its container/app reference at the sandbox.
            ->repoint('router', 'container')
            ->repoint('events', 'container')
            ->repoint('db', 'app')
            ->repoint('auth', 'app')
            ->repoint(ConsoleKernel::class, 'app')
            ->repoint(Gate::class, 'container')
            // Per-test state on shared singletons.
            ->flush('auth', 'forgetGuards');
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

            $service = $sandbox->make($id);

            (function () use ($property, $sandbox): void {
                $this->{$property} = $sandbox;
            })->call($service);
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
