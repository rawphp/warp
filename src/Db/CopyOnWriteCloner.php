<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use RuntimeException;

final class CopyOnWriteCloner
{
    public function clone(string $source, string $destination): void
    {
        if (! is_dir($source)) {
            throw new RuntimeException('[warp] clone source missing: '.$source);
        }

        Dirs::ensure(dirname($destination));
        Dirs::delete($destination);

        $exit = self::copy(match (PHP_OS_FAMILY) {
            // APFS clonefile: 3.7GB datadir in ~1.65s, zero extra disk.
            'Darwin' => ['cp', '-Rpc', $source, $destination],
            // XFS/btrfs reflink; auto falls back to a full copy on ext4.
            'Linux' => ['cp', '-a', '--reflink=auto', $source, $destination],
            default => ['cp', '-Rp', $source, $destination],
        });

        // -c requires APFS; on other volumes fall back to a plain copy — correctness over speed.
        if ($exit !== 0 && PHP_OS_FAMILY === 'Darwin') {
            Dirs::delete($destination);
            $exit = self::copy(['cp', '-Rp', $source, $destination]);
        }

        if ($exit !== 0) {
            throw new RuntimeException("[warp] failed to clone {$source} -> {$destination} (cp exit {$exit})");
        }
    }

    /** @param list<string> $command */
    private static function copy(array $command): int
    {
        $process = proc_open($command, [
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes);

        return $process === false ? 1 : proc_close($process);
    }
}
