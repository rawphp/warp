<?php

declare(strict_types=1);

namespace RawPHP\Warp\Db;

use RawPHP\Warp\Support\Dirs;

/**
 * @deprecated Use {@see Dirs}. Kept as a class alias for
 *             any out-of-tree call sites that imported the original location
 *             while Dirs lived under Db\ (it was always a general FS helper).
 */
class_alias(Dirs::class, __NAMESPACE__.'\Dirs');
