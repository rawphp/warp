<?php

declare(strict_types=1);

namespace Warp\Concerns;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use Warp\Attributes\Isolated;
use Warp\ResetManifest;
use Warp\WarmApplicationFactory;
use Warp\WarpMode;

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

            return $this->createClassicApplication();
        }

        $this->warpSandboxActive = true;

        return WarmApplicationFactory::sandbox(
            fn (): Application => $this->createClassicApplication(),
            $this->warpResetManifest(),
        );
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
