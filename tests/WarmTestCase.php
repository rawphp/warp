<?php

declare(strict_types=1);

namespace Warp\Tests;

abstract class WarmTestCase extends TestCase
{
    protected function setUp(): void
    {
        putenv('WARP_MODE=1');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('WARP_MODE');
    }
}
