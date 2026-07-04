<?php

declare(strict_types=1);

namespace Warp\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Testbench;
use Warp\Concerns\InteractsWithWarmApplication;

abstract class TestCase extends Testbench
{
    use InteractsWithWarmApplication;

    protected function createClassicApplication(): Application
    {
        return parent::createApplication();
    }
}
