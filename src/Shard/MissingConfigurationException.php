<?php

declare(strict_types=1);

namespace RawPHP\Warp\Shard;

use RuntimeException;

/**
 * @internal Shard discovery error; not a host-facing API.
 */
final class MissingConfigurationException extends RuntimeException {}
