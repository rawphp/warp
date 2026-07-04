<?php

declare(strict_types=1);

pest()->extend(Warp\Tests\TestCase::class)->in('Unit');
pest()->extend(Warp\Tests\WarmTestCase::class)->in('Feature');
