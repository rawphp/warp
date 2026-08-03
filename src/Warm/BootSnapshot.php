<?php

declare(strict_types=1);

namespace RawPHP\Warp\Warm;

use Closure;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use RawPHP\Warp\Support\ObjectAccess;
use ReflectionProperty;

/**
 * Boot-time process state that must be restored before every sandbox clone.
 *
 * Captured once when the warm base boots; reapplied so mid-run mutations
 * (Event::listen, Model boots, Artisan bootstrap wipe, base instance leaks)
 * do not poison later tests in the same worker.
 */
final class BootSnapshot
{
    /**
     * @param  list<Closure>  $consoleBootstrappers
     * @param  array<string, array<array-key, mixed>>  $dispatcherListeners
     * @param  array<class-string, bool>  $bootedModels
     * @param  array<class-string, array<int, Closure>>  $bootedCallbacks
     * @param  array<string, true>  $baseInstanceKeys
     */
    public function __construct(
        private readonly array $consoleBootstrappers,
        private readonly array $dispatcherListeners,
        private readonly array $bootedModels,
        private readonly array $bootedCallbacks,
        private readonly array $baseInstanceKeys,
    ) {}

    public static function capture(Application $base): self
    {
        $bootedCallbacks = [];

        if (class_exists(Model::class)) {
            $bootedCallbacks = (new ReflectionProperty(Model::class, 'bootedCallbacks'))->getValue();
        }

        return new self(
            self::consoleBootstrappers(),
            self::dispatcherState($base->make('events')),
            self::eloquentBootedModels(),
            $bootedCallbacks,
            array_fill_keys(array_keys(self::containerInstances($base)), true),
        );
    }

    /** @return array<string, true> */
    public function baseInstanceKeys(): array
    {
        return $this->baseInstanceKeys;
    }

    /**
     * Drop base instances that accumulated mid-run (provider closures that
     * resolve through the base) and restore dispatcher / Eloquent / Artisan
     * process statics to their boot-time values.
     */
    public function restoreOnto(Application $base): void
    {
        $this->pruneBaseInstances($base);

        if (self::consoleBootstrappers() === [] && $this->consoleBootstrappers !== []) {
            (new ReflectionProperty(ConsoleApplication::class, 'bootstrappers'))
                ->setValue(null, $this->consoleBootstrappers);
        }

        self::restoreDispatcherState($base->make('events'), $this->dispatcherListeners);

        if (class_exists(Model::class)) {
            (new ReflectionProperty(Model::class, 'booted'))->setValue(null, $this->bootedModels);
            (new ReflectionProperty(Model::class, 'bootedCallbacks'))->setValue(null, $this->bootedCallbacks);

            // Event::fake() also swaps the Eloquent STATIC dispatcher
            // (Model::setEventDispatcher($fake)); classic mode undoes that on
            // the next cold boot via DatabaseServiceProvider, testbench in its
            // per-test hooks — plain Laravel TestCases get neither in warm
            // mode, so the previous test's fake would keep swallowing model
            // events. Point the static back at the real shared dispatcher.
            Model::setEventDispatcher($base->make('events'));
        }
    }

    private function pruneBaseInstances(Application $base): void
    {
        $keys = $this->baseInstanceKeys;

        ObjectAccess::write($base, function () use ($keys): void {
            foreach (array_keys($this->instances) as $key) {
                if (! isset($keys[$key])) {
                    unset($this->instances[$key], $this->resolved[$key]);
                }
            }
        });
    }

    /** @return array<string, mixed> */
    public static function containerInstances(Application $app): array
    {
        return ObjectAccess::read($app, fn (): array => $this->instances);
    }

    /** @return array<class-string, bool> */
    private static function eloquentBootedModels(): array
    {
        if (! class_exists(Model::class)) {
            return [];
        }

        return (new ReflectionProperty(Model::class, 'booted'))->getValue();
    }

    /** @return list<Closure> */
    private static function consoleBootstrappers(): array
    {
        return (new ReflectionProperty(ConsoleApplication::class, 'bootstrappers'))->getValue();
    }

    /** @return array<string, array<array-key, mixed>> */
    private static function dispatcherState(object $dispatcher): array
    {
        return ObjectAccess::read($dispatcher, fn (): array => [
            'listeners' => $this->listeners,
            'wildcards' => $this->wildcards,
            'wildcardsCache' => $this->wildcardsCache,
        ]);
    }

    /** @param array<string, array<array-key, mixed>> $state */
    private static function restoreDispatcherState(object $dispatcher, array $state): void
    {
        if ($state === []) {
            return;
        }

        ObjectAccess::write($dispatcher, function () use ($state): void {
            $this->listeners = $state['listeners'];
            $this->wildcards = $state['wildcards'];
            $this->wildcardsCache = $state['wildcardsCache'];
        });
    }
}
