<?php

declare(strict_types=1);

namespace RawPHP\Warp\Concerns;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;
use RawPHP\Warp\Attributes\Isolated;
use RawPHP\Warp\Db\SnapshotDatabaseManager;
use RawPHP\Warp\ResetManifest;
use RawPHP\Warp\WarmApplicationFactory;
use RawPHP\Warp\WarpMode;
use ReflectionClass;

trait InteractsWithWarmApplication
{
    private bool $warpSandboxActive = false;

    /** The project's original (per-test, cold) application factory. */
    abstract protected function createClassicApplication(): Application;

    /** Override to extend the reset manifest with project-specific services. */
    protected function warpResetManifest(): ResetManifest
    {
        return ResetManifest::default();
    }

    public function createApplication(): Application
    {
        if (! WarpMode::enabled() || $this->warpShouldIsolate()) {
            $this->warpSandboxActive = false;

            return $this->warpProvisionDatabase($this->createClassicApplication());
        }

        $this->warpSandboxActive = true;

        return $this->warpProvisionDatabase(WarmApplicationFactory::sandbox(
            fn (): Application => $this->createClassicApplication(),
            $this->warpResetManifest(),
        ));
    }

    /** WARP_DB=1: point this app instance at the per-worker snapshot clone. */
    private function warpProvisionDatabase(Application $app): Application
    {
        if (WarpMode::databaseEnabled()) {
            SnapshotDatabaseManager::apply($app);
        }

        return $app;
    }

    /**
     * Fresh committed DB state via a sub-second re-clone from the golden
     * snapshot — for tests that must commit (multi-connection, DDL, …).
     */
    protected function warpRecycleDatabase(): void
    {
        SnapshotDatabaseManager::recycle($this->app);
    }

    public function usingWarmSandbox(): bool
    {
        return $this->warpSandboxActive;
    }

    protected function warpShouldIsolate(): bool
    {
        if ((new ReflectionClass(static::class))->getAttributes(Isolated::class) !== []) {
            return true;
        }

        return method_exists($this, 'groups')
            && in_array('warp-isolated', $this->groups(), true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (! $this->warpSandboxActive) {
            return;
        }

        $this->warpSandboxActive = false;

        $report = WarmApplicationFactory::checkHermeticity();

        if (! $report->clean()) {
            Assert::fail('[warp] hermeticity violation — this test leaked shared state: '.$report->describe());
        }
    }
}
