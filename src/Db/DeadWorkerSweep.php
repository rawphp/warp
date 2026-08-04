<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use RawPHP\Warp\Support\Dirs;
use RawPHP\Warp\Support\ProcessProbe;

/**
 * Reap orphaned WARP_DB worker runtime dirs whose owning test process died
 * (crashed worker, kill -9). Never deletes a live mysqld's datadir.
 *
 * @internal Package snapshot-DB plumbing; not host-facing.
 */
final class DeadWorkerSweep
{
    /** Skip dirs younger than this — a sibling may still be mid-provision. */
    private const MID_PROVISION_GRACE_SECONDS = 60;

    /** Wait after SIGTERM before re-probing a stubborn mysqld. */
    private const TERM_SETTLE_US = 500_000;

    public static function run(string $runtimeDir): void
    {
        foreach (glob($runtimeDir.'/w*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) > time() - self::MID_PROVISION_GRACE_SECONDS) {
                continue;
            }

            $owner = (int) @file_get_contents($dir.'/owner.pid');

            if ($owner > 0 && ProcessProbe::alive($owner)) {
                continue;
            }

            $mysqldPid = (int) @file_get_contents($dir.'/datadir/warp-mysqld.pid');

            if ($mysqldPid > 0 && ProcessProbe::alive($mysqldPid)) {
                ProcessProbe::signal($mysqldPid, 15);
                usleep(self::TERM_SETTLE_US);

                // Still running after graceful TERM — leave for a later sweep.
                if (ProcessProbe::alive($mysqldPid)) {
                    continue;
                }
            }

            Dirs::delete($dir);
        }
    }
}
