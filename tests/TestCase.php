<?php

declare(strict_types=1);

namespace Warp\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    /**
     * The project's original (per-test, cold) application factory.
     * Warp's warm trait sandboxes this; package tests also call it directly.
     */
    protected function createClassicApplication(): Application
    {
        return parent::createApplication();
    }
}
