<?php

declare(strict_types=1);

namespace RawPHP\Warp;

use Closure;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use RawPHP\Warp\Sentinel\HermeticitySentinel;
use RawPHP\Warp\Sentinel\LeakReport;
use ReflectionProperty;

final class WarmApplicationFactory
{
    private static ?Application $base = null;

    private static int $bootCount = 0;

    private static ?HermeticitySentinel $sentinel = null;

    /**
     * Console command registrars captured at base boot. Laravel's per-test
     * teardown calls Artisan::forgetBootstrappers(), wiping this PROCESS
     * static; classic mode re-registers it on the next cold boot, but a warm
     * base boots once — without restoration, any sandbox that builds the
     * console application after the first teardown has zero commands
     * ("The command \"migrate:fresh\" does not exist").
     *
     * @var list<Closure>
     */
    private static array $consoleBootstrappers = [];

    /**
     * The shared event dispatcher's listener tables as captured at base boot.
     * The dispatcher object is shared by every sandbox, so listeners a test
     * registers (Event::listen) would otherwise persist for the rest of the
     * worker — e.g. a test throwing from a listener to simulate a failure
     * poisons every later test that fires the same event. Restored per
     * sandbox.
     *
     * @var array<string, array<array-key, mixed>>
     */
    private static array $dispatcherListeners = [];

    /**
     * Eloquent's booted-model memo as captured at base boot. Models boot
     * lazily once per process, registering their event listeners on the
     * shared dispatcher at that moment. Restoring the dispatcher to its boot
     * snapshot orphans listeners of models booted mid-run, so the memo is
     * rolled back with it — a model first used earlier re-boots in the next
     * sandbox and re-registers, exactly as a classic cold boot does via
     * Model::clearBootedModels().
     *
     * @var array<class-string, bool>
     */
    private static array $bootedModels = [];

    /**
     * Eloquent's booted-callback static as captured at base boot. Rolled back
     * together with the booted-model memo: bootHasEvents() APPENDS its
     * "register the attribute observers" callback via Model::whenBooted() on
     * every re-boot, so leaving this static alone makes test N fire each
     * #[ObservedBy] observer N times. The framework's clearBootedModels()
     * clears both statics together for the same reason.
     *
     * @var array<class-string, array<int, Closure>>
     */
    private static array $bootedCallbacks = [];

    /**
     * The base's instance ids as captured at boot; additions beyond this
     * snapshot are pruned at every sandbox creation (see sandbox()).
     *
     * @var array<string, true>
     */
    private static array $baseInstanceKeys = [];

    /**
     * Return a per-test sandbox cloned from the once-booted base application.
     *
     * @param  Closure(): Application  $createClassicApplication
     */
    public static function sandbox(Closure $createClassicApplication, ResetManifest $manifest): Application
    {
        if (! self::$base instanceof Application) {
            self::$base = $createClassicApplication();
            self::$bootCount++;

            // Resolve the DB manager into the base so every sandbox shares the
            // same manager (and therefore the same PDO connections): this keeps
            // RefreshDatabase's once-per-process migrate + per-test transaction
            // model working unchanged in warm mode.
            self::$base->make('db');

            // Resolve the queue manager into the base too. Otherwise the
            // FIRST-ever job dispatch in the process builds it through the Bus
            // dispatcher's closure (which captured the base app at deferred
            // provider load), leaving the manager and its first connection
            // bound to the BASE container — the dispatched job then resolves
            // its dependencies from the base, ignoring the running test's
            // container bindings/mocks. Resolved up front, the manifest's
            // per-sandbox repoint governs it from the very first test.
            if (self::$base->bound('queue')) {
                self::$base->make('queue');
            }

            $base = self::$base;

            if (getenv('WARP_TRACE_BASE_RESOLVE') !== false) {
                $base->resolving(function ($object, $container) use ($base): void {
                    if ($container === $base) {
                        file_put_contents(
                            '/tmp/warp-base-resolve.log',
                            get_class($object)."\n".(new \Exception)->getTraceAsString()."\n\n",
                            FILE_APPEND,
                        );
                    }
                });
            }

            // Diagnostic probe (WARP_SENTINEL_BASE_INSTANCES=1): any service
            // resolved INTO THE BASE mid-run (through a boot-captured closure
            // or stale reference) becomes shared state for every later
            // sandbox — e.g. a base-resolved 'url' registers a 'routes'
            // rebinding whose inherited callbacks send later sandboxes into
            // infinite recursion. With the probe on, the leaking TEST is
            // named at its own teardown instead of silently poisoning the
            // worker. Opt-in because leaks cascade-fail every later test in
            // the worker (the first named test is the culprit).
            $probes = [];

            if (getenv('WARP_SENTINEL_BASE_INSTANCES') !== false) {
                $probes['base.instances'] = function () use ($base): string {
                    $ids = array_keys((function (): array {
                        return $this->instances;
                    })->call($base));

                    sort($ids);

                    return implode('|', $ids);
                };
            }

            self::$sentinel = HermeticitySentinel::capture(self::$base, $probes);

            self::$consoleBootstrappers = self::consoleBootstrappers();

            self::$dispatcherListeners = self::dispatcherState(self::$base->make('events'));

            self::$bootedModels = self::eloquentBootedModels();

            if (class_exists(Model::class)) {
                self::$bootedCallbacks = (new ReflectionProperty(Model::class, 'bootedCallbacks'))->getValue();
            }

            self::$baseInstanceKeys = array_fill_keys(array_keys(self::containerInstances(self::$base)), true);
        }

        // Provider closures capture the provider ($this->app = the base), so
        // `fn () => new X($this->app)`-style factories resolve THROUGH THE
        // BASE no matter which sandbox asked — mid-run the base quietly
        // accumulates SessionManagers, maintenance managers, etc., silently
        // shared with every later sandbox. Prune anything beyond the boot
        // snapshot so a leak dies with the test that caused it.
        (function (): void {
            foreach (array_keys($this->instances) as $key) {
                if (! isset(WarmApplicationFactory::baseInstanceKeys()[$key])) {
                    unset($this->instances[$key], $this->resolved[$key]);
                }
            }
        })->call(self::$base);

        if (self::consoleBootstrappers() === [] && self::$consoleBootstrappers !== []) {
            (new ReflectionProperty(ConsoleApplication::class, 'bootstrappers'))
                ->setValue(null, self::$consoleBootstrappers);
        }

        self::restoreDispatcherState(self::$base->make('events'), self::$dispatcherListeners);

        if (class_exists(Model::class)) {
            (new ReflectionProperty(Model::class, 'booted'))->setValue(null, self::$bootedModels);
            (new ReflectionProperty(Model::class, 'bootedCallbacks'))->setValue(null, self::$bootedCallbacks);

            // Event::fake() also swaps the Eloquent STATIC dispatcher
            // (Model::setEventDispatcher($fake)); classic mode undoes that on
            // the next cold boot via DatabaseServiceProvider, testbench in its
            // per-test hooks — plain Laravel TestCases get neither in warm
            // mode, so the previous test's fake would keep swallowing model
            // events. Point the static back at the real shared dispatcher.
            Model::setEventDispatcher(self::$base->make('events'));
        }

        $sandbox = clone self::$base;

        // The clone's instances array still anchors 'app'/Container at the base;
        // mirror Application::registerBaseBindings() for the sandbox and give it
        // its own config repository (array items copy by value on clone).
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        $sandbox->instance('config', clone $sandbox->make('config'));

        Container::setInstance($sandbox);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($sandbox);

        $manifest->apply($sandbox, self::$base);

        return $sandbox;
    }

    public static function base(): ?Application
    {
        return self::$base;
    }

    /**
     * The base's boot-time instance ids (see the prune step in sandbox()).
     *
     * @return array<string, true>
     */
    public static function baseInstanceKeys(): array
    {
        return self::$baseInstanceKeys;
    }

    /** @return array<string, mixed> */
    private static function containerInstances(Application $app): array
    {
        return (function (): array {
            return $this->instances;
        })->call($app);
    }

    public static function bootCount(): int
    {
        return self::$bootCount;
    }

    /** Diff current global state against the pristine fingerprint; scrap a corrupted base. */
    public static function checkHermeticity(): LeakReport
    {
        if (! self::$base instanceof Application || self::$sentinel === null) {
            return new LeakReport([], false);
        }

        $report = self::$sentinel->check(self::$base);

        if ($report->baseCorrupted) {
            self::scrap();
        }

        return $report;
    }

    /** Drop the warm base; the next sandbox request boots a pristine one. */
    public static function scrap(): void
    {
        self::$base = null;
        self::$sentinel = null;
        self::$consoleBootstrappers = [];
        self::$dispatcherListeners = [];
        self::$bootedModels = [];
        self::$bootedCallbacks = [];
        self::$baseInstanceKeys = [];
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
        return (function (): array {
            return [
                'listeners' => $this->listeners,
                'wildcards' => $this->wildcards,
                'wildcardsCache' => $this->wildcardsCache,
            ];
        })->call($dispatcher);
    }

    /** @param array<string, array<array-key, mixed>> $state */
    private static function restoreDispatcherState(object $dispatcher, array $state): void
    {
        if ($state === []) {
            return;
        }

        (function () use ($state): void {
            $this->listeners = $state['listeners'];
            $this->wildcards = $state['wildcards'];
            $this->wildcardsCache = $state['wildcardsCache'];
        })->call($dispatcher);
    }
}
