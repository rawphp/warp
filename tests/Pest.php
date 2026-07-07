<?php

declare(strict_types=1);
use RawPHP\Warp\Tests\TestCase;
use RawPHP\Warp\Tests\WarmTestCase;

pest()->extend(TestCase::class)->in('Unit');
pest()->extend(WarmTestCase::class)->in('Feature');
