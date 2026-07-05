<?php

declare(strict_types=1);

pest()->extend(RawPHP\Warp\Tests\TestCase::class)->in('Unit');
pest()->extend(RawPHP\Warp\Tests\WarmTestCase::class)->in('Feature');
