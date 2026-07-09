<?php

declare(strict_types=1);

namespace RawPHP\Warp\Cli;

use InvalidArgumentException;
use RawPHP\Warp\Timing\TimingStore;

final class TimingStoreArgumentParser
{
    public function __construct(
        public readonly TimingStore $store,
        public readonly string $dirLabel,
    ) {}

    /**
     * @param  list<string>  $args
     * @param  callable(string): bool  $consume
     */
    public static function parse(array $args, callable $consume): self
    {
        $store = TimingStore::fromEnv();
        $dirLabel = 'configured timings dir';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--timings-dir=')) {
                $dir = substr($arg, strlen('--timings-dir='));

                if ($dir === '') {
                    throw new InvalidArgumentException('[warp] --timings-dir must not be empty');
                }

                $store = new TimingStore($dir);
                $dirLabel = $dir;

                continue;
            }

            if ($consume($arg)) {
                continue;
            }

            if (str_starts_with($arg, '--')) {
                throw new InvalidArgumentException("[warp] unknown option: {$arg}");
            }

            throw new InvalidArgumentException("[warp] unknown argument: {$arg}");
        }

        return new self($store, $dirLabel);
    }
}
