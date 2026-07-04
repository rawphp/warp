#!/usr/bin/env php
<?php

declare(strict_types=1);

// Usage: gen-trivial-tests.php <target-dir> <files> <tests-per-file>
[, $dir, $files, $perFile] = $argv + [null, null, '20', '10'];

if (! is_string($dir) || $dir === '') {
    fwrite(STDERR, "usage: gen-trivial-tests.php <target-dir> <files> <tests-per-file>\n");
    exit(1);
}

if (! is_dir($dir)) {
    mkdir($dir, 0777, true);
}

for ($f = 1; $f <= (int) $files; $f++) {
    $body = "<?php\n\n";

    for ($t = 1; $t <= (int) $perFile; $t++) {
        $body .= "it('warp bench trivial {$f}-{$t}', function () {\n    expect(true)->toBeTrue();\n});\n\n";
    }

    file_put_contents("{$dir}/WarpBenchF{$f}Test.php", $body);
}

echo "generated {$files} files x {$perFile} tests in {$dir}\n";
