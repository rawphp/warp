<?php

declare(strict_types=1);

use RawPHP\Warp\Db\MysqlBinaries;
use RawPHP\Warp\Tests\TestCase;
use RawPHP\Warp\Tests\WarmTestCase;

pest()->extend(TestCase::class)->in('Unit', 'Integration');
pest()->extend(WarmTestCase::class)->in('Feature');

/** True when a real mysqld is discoverable — Integration tests skip cleanly otherwise. */
function mysqldAvailable(): bool
{
    static $available = null;

    if ($available === null) {
        try {
            MysqlBinaries::discover();
            $available = true;
        } catch (RuntimeException) {
            $available = false;
        }
    }

    return $available;
}
