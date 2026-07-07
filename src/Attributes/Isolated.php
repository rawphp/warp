<?php

declare(strict_types=1);

namespace RawPHP\Warp\Attributes;

use Attribute;

/**
 * Marks a test class that must never share a warm process application —
 * Warp falls back to a fresh classic boot for its tests.
 * Pest closure tests use ->group('warp-isolated') instead.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Isolated {}
