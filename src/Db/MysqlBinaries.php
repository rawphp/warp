<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use RuntimeException;

final class MysqlBinaries
{
    public function __construct(
        public readonly string $mysqld,
        public readonly string $mysqladmin,
    ) {}

    public static function discover(?string $mysqld = null): self
    {
        $mysqld ??= (getenv('WARP_DB_MYSQLD') ?: null) ?? self::firstExecutable(self::mysqldCandidates());

        if ($mysqld === null || ! is_executable($mysqld) || is_dir($mysqld)) {
            throw new RuntimeException(
                '[warp] mysqld not found — install MySQL 8 or point WARP_DB_MYSQLD at the binary.',
            );
        }

        $mysqladmin = self::firstExecutable([
            dirname($mysqld).'/mysqladmin',
            ...self::onPath('mysqladmin'),
        ]);

        if ($mysqladmin === null) {
            throw new RuntimeException('[warp] mysqladmin not found next to '.$mysqld.' or on PATH.');
        }

        return new self($mysqld, $mysqladmin);
    }

    public function version(): string
    {
        exec(escapeshellarg($this->mysqld).' --version 2>&1', $output, $exit);

        $banner = trim($output[0] ?? '');

        if ($exit !== 0 || $banner === '') {
            throw new RuntimeException('[warp] could not read a version banner from '.$this->mysqld);
        }

        return $banner;
    }

    /** @return list<string> */
    private static function mysqldCandidates(): array
    {
        return [
            ...self::onPath('mysqld'),
            '/opt/homebrew/opt/mysql/bin/mysqld',
            '/usr/local/opt/mysql/bin/mysqld',
            '/usr/local/mysql/bin/mysqld',
            '/usr/sbin/mysqld',
            '/usr/bin/mysqld',
        ];
    }

    /** @return list<string> */
    private static function onPath(string $binary): array
    {
        return array_map(
            static fn (string $dir): string => $dir.'/'.$binary,
            array_values(array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')))),
        );
    }

    /** @param list<string> $paths */
    private static function firstExecutable(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_executable($path) && ! is_dir($path)) {
                return $path;
            }
        }

        return null;
    }
}
