<?php

declare(strict_types=1);

namespace RawPHP\Warp\Warm;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Pagination\PaginationState;
use Illuminate\Testing\ParallelTestingServiceProvider;
use RawPHP\Warp\ResetManifest;
use RawPHP\Warp\Support\ObjectAccess;

/**
 * Named default sandbox reset steps. Owns construction of the fully
 * registered default {@see ResetManifest}; hosts still enter via
 * {@see ResetManifest::default()} which thin-delegates here.
 *
 * Each method is one leak class discovered against real Laravel suites —
 * keep the rationale comment next to the step that fixes it.
 */
final class DefaultResetSteps
{
    /**
     * Build a fully registered default manifest. Defaults depend on the DSL;
     * the DSL does not own Laravel-default registration.
     */
    public static function manifest(): ResetManifest
    {
        return (new ResetManifest)
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
            // Octane maintains a curated list of managers that capture the
            // boot application and must be handed the current one per
            // request; warm sandboxes need the same treatment per test.
            ->repoint('validator', 'container')
            ->repoint('log', 'app')
            ->repoint('redis', 'app')
            ->repoint(ConsoleKernel::class, 'app')
            ->repoint(HttpKernel::class, 'app')
            ->repoint(Gate::class, 'container')
            // Per-test state on shared singletons.
            ->flush('auth', 'forgetGuards')
            ->add(self::rebindGateUserResolver(...))
            ->add(self::rebindPaginationState(...))
            ->add(self::clearConsoleArtisanCache(...))
            ->add(self::repointMailManager(...))
            ->add(self::repointChannelManager(...))
            ->add(self::repointBroadcastManager(...))
            ->add(self::rebindUrlGeneratorResolvers(...))
            ->add(self::stripInheritedRoutesRebound(...))
            ->add(self::flushRouteControllers(...))
            ->add(self::repointQueueManager(...))
            ->add(self::repointRateLimiterCache(...))
            ->add(self::repointParallelTestingProvider(...));
    }

    /**
     * The Gate's user resolver is a closure captured at boot that closes
     * over the BASE application ("$app['auth']->userResolver()"). Because
     * 'auth' is resolved per-sandbox rather than at boot, that closure
     * reads the wrong container and resolves a null user — every
     * policy check then fails (403). Re-point the resolver at the
     * sandbox's auth so authenticated policy checks work in warm mode.
     */
    private static function rebindGateUserResolver(Application $sandbox): void
    {
        if (! $sandbox->resolved(Gate::class)) {
            return;
        }

        $gate = $sandbox->make(Gate::class);

        ObjectAccess::write($gate, function () use ($sandbox): void {
            $this->userResolver = fn () => call_user_func($sandbox['auth']->userResolver());
        });
    }

    /**
     * The paginator's current-page/path/query/cursor resolvers are static
     * closures registered ONCE at boot by PaginationServiceProvider, closing
     * over the BASE application ("$app['request']->input('page')"). A warm
     * sandbox never re-runs that registration, so every ->paginate() reads
     * the base app's request (no ?page=N) and silently returns page 1.
     * Re-bind all pagination resolvers to the current sandbox — this is the
     * exact framework contract the service provider uses at boot.
     */
    private static function rebindPaginationState(Application $sandbox): void
    {
        if (class_exists(PaginationState::class)) {
            PaginationState::resolveUsing($sandbox);
        }
    }

    /**
     * The console kernel caches its Artisan console application (and
     * every Command object inside it) bound to whichever sandbox first
     * ran a command. Laravel's teardown flush()es that sandbox, so a
     * later artisan call would resolve through a dead container
     * ("Target class [env] does not exist"). Drop the cache so each
     * sandbox rebuilds Artisan against itself on first use.
     */
    private static function clearConsoleArtisanCache(Application $sandbox): void
    {
        if (! $sandbox->resolved(ConsoleKernel::class)) {
            return;
        }

        $kernel = $sandbox->make(ConsoleKernel::class);

        if (method_exists($kernel, 'setArtisan')) {
            $kernel->setArtisan(null);
        }
    }

    /**
     * The mail manager captures the boot app and caches built mailers
     * (which capture views, queues and config from whichever sandbox built
     * them). Point it at the current sandbox and drop the mailer cache —
     * boot-registered custom creators live in separate properties and survive.
     */
    private static function repointMailManager(Application $sandbox): void
    {
        if (! $sandbox->resolved('mail.manager')) {
            return;
        }

        $manager = $sandbox->make('mail.manager');

        ObjectAccess::set($manager, 'app', $sandbox);
        $manager->forgetMailers();
    }

    /**
     * The notification channel manager captures the boot container/config
     * and caches built drivers. Point it at the sandbox and empty the
     * driver cache so channels rebuild against live services.
     */
    private static function repointChannelManager(Application $sandbox): void
    {
        if (! class_exists(ChannelManager::class) || ! $sandbox->resolved(ChannelManager::class)) {
            return;
        }

        $manager = $sandbox->make(ChannelManager::class);

        ObjectAccess::write($manager, function () use ($sandbox): void {
            $this->container = $sandbox;
            $this->config = $sandbox->make('config');
            $this->drivers = [];
        });
    }

    /**
     * The broadcast manager captures the boot app and caches built
     * drivers. Point it at the sandbox and empty the driver cache.
     */
    private static function repointBroadcastManager(Application $sandbox): void
    {
        if (! class_exists(BroadcastManager::class)
            || ! $sandbox->resolved(BroadcastManager::class)) {
            return;
        }

        $manager = $sandbox->make(BroadcastManager::class);

        ObjectAccess::write($manager, function () use ($sandbox): void {
            $this->app = $sandbox;
            $this->drivers = [];
        });
    }

    /**
     * RoutingServiceProvider's 'url' extender wires the UrlGenerator's
     * session and key resolvers as closures over the PROVIDER
     * ($this->app = the base). Left alone, every sandbox's
     * UrlGenerator reads the session and app keys from the BASE — the
     * first failed FormRequest validation (redirect ->previous())
     * resolves a SessionManager INTO the base and session state is
     * silently shared across every later test in the worker. Stack a
     * sandbox-side extender after the framework's so the resolvers
     * point at the resolving sandbox.
     */
    private static function rebindUrlGeneratorResolvers(Application $sandbox): void
    {
        $sandbox->extend('url', function ($url, Application $app) {
            if (method_exists($url, 'setSessionResolver')) {
                $url->setSessionResolver(function () use ($app) {
                    return $app->resolved('session') || $app->bound('session')
                        ? $app->make('session')
                        : null;
                });
            }

            if (method_exists($url, 'setKeyResolver')) {
                $url->setKeyResolver(function () use ($app): array {
                    $config = $app->make('config');

                    return [$config->get('app.key'), ...($config->get('app.previous_keys') ?? [])];
                });
            }

            return $url;
        });
    }

    /**
     * RoutingServiceProvider's 'url' extender registers a 'routes'
     * rebinding callback on whichever container resolves 'url'. A
     * sandbox inheriting that callback from the base loops forever on
     * its next url resolution (build url → instance 'routes' → fire
     * callback → $app['url'] mid-build → build url → ...), eventually
     * killing the whole worker with an OOM/stack fatal. Strip the
     * inherited callbacks — url re-registers its own on rebuild.
     */
    private static function stripInheritedRoutesRebound(Application $sandbox): void
    {
        ObjectAccess::write($sandbox, function (): void {
            unset($this->reboundCallbacks['routes']);
        });
    }

    /**
     * Route::getController() caches the resolved controller INSTANCE
     * on the shared Route object — a controller resolved in one test
     * (possibly with that test's mock injected) would serve every
     * later test hitting the same route. Octane flushes this per
     * request; flush it per sandbox.
     */
    private static function flushRouteControllers(Application $sandbox): void
    {
        if (! $sandbox->resolved('router')) {
            return;
        }

        foreach ($sandbox->make('router')->getRoutes() as $route) {
            $route->flushController();
        }
    }

    /**
     * The queue manager caches its queue connections; a DatabaseQueue
     * built in an earlier sandbox holds that sandbox's DB Connection
     * object, and pushing onto it later triggers a reconnect that
     * DISCONNECTS the live connection carrying the current test's
     * RefreshDatabase transaction — silently rolling back everything
     * the test wrote. Empty the cache and point the manager at the
     * sandbox so queue connections rebuild against live services.
     */
    private static function repointQueueManager(Application $sandbox): void
    {
        if (! $sandbox->resolved('queue')) {
            return;
        }

        $queue = $sandbox->make('queue');

        ObjectAccess::write($queue, function () use ($sandbox): void {
            $this->app = $sandbox;
            $this->connections = [];
        });
    }

    /**
     * Apps register named limiters at boot (RateLimiter::for in a
     * provider), resolving the RateLimiter singleton into the base
     * with the BOOT cache store captured inside it. Sandboxes get
     * fresh cache repositories, but the shared limiter would keep
     * counting into the boot store — throttle hits accumulating
     * across every test in the worker until requests 429. Swap the
     * limiter's cache for the sandbox's while preserving the
     * boot-registered named limiters (a forget would lose them).
     */
    private static function repointRateLimiterCache(Application $sandbox): void
    {
        if (! class_exists(RateLimiter::class) || ! $sandbox->resolved(RateLimiter::class)) {
            return;
        }

        $limiter = $sandbox->make(RateLimiter::class);

        ObjectAccess::write($limiter, function () use ($sandbox): void {
            $this->cache = $sandbox->make('cache')
                ->driver($sandbox->make('config')->get('cache.limiter'));
        });
    }

    /**
     * Laravel's parallel-testing callbacks (cache prefix, compiled-view
     * path, test-database switch) are closures over the
     * ParallelTestingServiceProvider instance, whose $app is the
     * application that booted it — the warm BASE. Left alone, every
     * paratest worker test writes its TEST_TOKEN suffixes through that
     * reference into the base config, corrupting the warm base. Point
     * the provider at the sandbox so those writes land on the sandbox's
     * own config clone.
     */
    private static function repointParallelTestingProvider(Application $sandbox): void
    {
        if (! class_exists(ParallelTestingServiceProvider::class)) {
            return;
        }

        $provider = $sandbox->getProvider(ParallelTestingServiceProvider::class);

        if ($provider !== null) {
            ObjectAccess::set($provider, 'app', $sandbox);
        }
    }
}
