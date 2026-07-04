#!/usr/bin/env php
<?php

declare(strict_types=1);

// Usage: compare-junit.php <classic.xml> <warm.xml>
// Exit 0 iff every test appears in both files with an identical outcome.
[, $a, $b] = $argv + [null, null, null];

function outcomes(string $file): array
{
    $xml = simplexml_load_file($file);

    if ($xml === false) {
        fwrite(STDERR, "cannot parse {$file}\n");
        exit(2);
    }

    $map = [];

    foreach ($xml->xpath('//testcase') as $tc) {
        $name = (string) $tc['classname'].'::'.(string) $tc['name'];
        $status = 'pass';

        if (isset($tc->failure)) {
            $status = 'fail';
        } elseif (isset($tc->error)) {
            $status = 'error';
        } elseif (isset($tc->skipped)) {
            $status = 'skip';
        }

        $map[$name] = $status;
    }

    ksort($map);

    return $map;
}

$classic = outcomes($a);
$warm = outcomes($b);
$diff = [];

foreach ($classic + $warm as $name => $_) {
    $sa = $classic[$name] ?? '<missing>';
    $sb = $warm[$name] ?? '<missing>';

    if ($sa !== $sb) {
        $diff[] = "{$name}: classic={$sa} warm={$sb}";
    }
}

printf("classic: %d tests, warm: %d tests\n", count($classic), count($warm));

if ($diff !== []) {
    echo 'DIVERGENCES ('.count($diff)."):\n".implode("\n", $diff)."\n";
    exit(1);
}

echo "PARITY OK\n";
