<?php

declare(strict_types=1);

use RawPHP\Warp\Db\MysqlBinaries;

pest()->extend(RawPHP\Warp\Tests\TestCase::class)->in('Unit', 'Integration');
pest()->extend(RawPHP\Warp\Tests\WarmTestCase::class)->in('Feature');

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
