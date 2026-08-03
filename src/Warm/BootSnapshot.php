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
 * do not poison later tests in the same worker. Each field carries the
 * failure mode that forced its capture — isolation code without that
 * rationale is one "safe cleanup" away from a silent cross-test leak.
 */
final class BootSnapshot
{
    /**
     * @param  list<Closure>  $consoleBootstrappers
     *                                               Console command registrars captured at base boot. Laravel's
     *                                               per-test teardown calls Artisan::forgetBootstrappers(), wiping
     *                                               this PROCESS static; classic mode re-registers it on the next
     *                                               cold boot, but a warm base boots once — without restoration,
     *                                               any sandbox that builds the console application after the first
     *                                               teardown has zero commands ("The command \"migrate:fresh\" does
     *                                               not exist").
     * @param  array<string, array<array-key, mixed>>  $dispatcherListeners
     *                                                                       The shared event dispatcher's listener tables as captured at
     *                                                                       base boot. The dispatcher object is shared by every sandbox, so
     *                                                                       listeners a test registers (Event::listen) would otherwise
     *                                                                       persist for the rest of the worker — e.g. a test throwing from
     *                                                                       a listener to simulate a failure poisons every later test that
     *                                                                       fires the same event. Restored per sandbox.
     * @param  array<class-string, bool>  $bootedModels
     *                                                   Eloquent's booted-model memo as captured at base boot. Models
     *                                                   boot lazily once per process, registering their event listeners
     *                                                   on the shared dispatcher at that moment. Restoring the
     *                                                   dispatcher to its boot snapshot orphans listeners of models
     *                                                   booted mid-run, so the memo is rolled back with it — a model
     *                                                   first used earlier re-boots in the next sandbox and
     *                                                   re-registers, exactly as a classic cold boot does via
     *                                                   Model::clearBootedModels().
     * @param  array<class-string, array<int, Closure>>  $bootedCallbacks
     *                                                                     Eloquent's booted-callback static as captured at base boot.
     *                                                                     Rolled back together with the booted-model memo:
     *                                                                     bootHasEvents() APPENDS its "register the attribute observers"
     *                                                                     callback via Model::whenBooted() on every re-boot, so leaving
     *                                                                     this static alone makes test N fire each #[ObservedBy] observer
     *                                                                     N times. The framework's clearBootedModels() clears both
     *                                                                     statics together for the same reason.
     * @param  array<string, true>  $baseInstanceKeys
     *                                                 The base's instance ids as captured at boot; additions beyond
     *                                                 this snapshot are pruned at every sandbox creation (see
     *                                                 {@see restoreOnto()} / {@see pruneBaseInstances()}).
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

    /**
     * Drop base instances that accumulated mid-run and restore dispatcher /
     * Eloquent / Artisan process statics to their boot-time values.
     */
    public function restoreOnto(Application $base): void
    {
        // Provider closures capture the provider ($this->app = the base), so
        // `fn () => new X($this->app)`-style factories resolve THROUGH THE
        // BASE no matter which sandbox asked — mid-run the base quietly
        // accumulates SessionManagers, maintenance managers, etc., silently
        // shared with every later sandbox. Prune anything beyond the boot
        // snapshot so a leak dies with the test that caused it.
        $this->pruneBaseInstances($base);

        // Artisan::forgetBootstrappers() empties the process static; put the
        // boot-captured registrars back before any sandbox builds console.
        if (self::consoleBootstrappers() === [] && $this->consoleBootstrappers !== []) {
            (new ReflectionProperty(ConsoleApplication::class, 'bootstrappers'))
                ->setValue(null, $this->consoleBootstrappers);
        }

        // Event::listen (and similar) on the shared dispatcher must not
        // outlive the test that registered them.
        self::restoreDispatcherState($base->make('events'), $this->dispatcherListeners);

        if (class_exists(Model::class)) {
            // Pair with dispatcher restore: orphaned mid-run listeners are
            // re-registered when models re-boot; bootedCallbacks rollback
            // prevents #[ObservedBy] observers from stacking N-fold.
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
    private static function containerInstances(Application $app): array
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
