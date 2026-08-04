<?php

declare(strict_types=1);

namespace RawPHP\Warp\Shard;

use RawPHP\Warp\Support\Paths;

/**
 * Resolve the test file list and timing-key root for `warp shard`.
 * Pure discovery policy — CLI I/O of the plan stays on ShardCommand.
 *
 * @internal Package shard CLI plumbing; not host-facing.
 */
final class ShardDiscovery
{
    /**
     * @param  list<string>  $paths  Explicit paths; empty means discover from config / tests/
     * @param  resource  $stderr
     * @return array{0: list<string>, 1: string} files, canonicalRoot
     */
    public static function resolve(
        string $cwd,
        array $paths,
        ?string $configuration,
        ?string $suffixOption,
        $stderr,
    ): array {
        $canonicalRoot = $cwd;

        if ($paths === []) {
            try {
                $files = SuiteDiscovery::discover($cwd, $configuration);
                if ($suffixOption !== null) {
                    fwrite($stderr, "[warp] --suffix={$suffixOption} ignored because phpunit.xml discovery controls test file suffixes\n");
                }
                $canonicalRoot = Paths::configRoot(SuiteDiscovery::rootConfigurationPath($cwd, $configuration), $cwd);
            } catch (MissingConfigurationException $exception) {
                if ($configuration !== null) {
                    throw $exception;
                }

                fwrite($stderr, "[warp] no phpunit.xml found - falling back to tests/Test.php discovery\n");
                $files = TestFileFinder::find(['tests'], $suffixOption ?? TestFileFinder::DEFAULT_SUFFIXES);
            }
        } else {
            if ($configuration !== null) {
                fwrite($stderr, "[warp] --configuration={$configuration} ignored for suite discovery (explicit test paths bypass discovery); still used for the timing-key root\n");
            }

            // Explicit paths bypass discovery but the timing-key root is still the
            // config dir the extension recorded against: honour --configuration for
            // the root, or probe for an implicit phpunit.xml exactly as discovery
            // would (finding 9). Only cwd-rooted runs with no config stay at getcwd.
            $configPath = SuiteDiscovery::rootConfigurationPath($cwd, $configuration);

            if ($configPath !== null) {
                $canonicalRoot = Paths::configRoot($configPath, $cwd);
            }

            $files = TestFileFinder::find($paths, $suffixOption ?? TestFileFinder::DEFAULT_SUFFIXES);
        }

        return [$files, $canonicalRoot];
    }
}
