<?php

declare(strict_types=1);

use RawPHP\Warp\Db\Dirs;
use RawPHP\Warp\Shard\SuiteDiscovery;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/warp-suite-discovery-'.bin2hex(random_bytes(4));
    Dirs::ensure($this->tmp);
});

afterEach(function () {
    Dirs::delete($this->tmp);
});

it('discovers the phpunit xml suite universe', function () {
    writeDiscoveryFile($this->tmp.'/tests/Unit/AlphaTest.php');
    writeDiscoveryFile($this->tmp.'/tests/Unit/SkipMeTest.php');
    writeDiscoveryFile($this->tmp.'/checks/HealthCheck.php');
    writeDiscoveryFile($this->tmp.'/checks/IgnoredTest.php');
    writeDiscoveryFile($this->tmp.'/explicit/ManualSpec.php');
    writeDiscoveryFile($this->tmp.'/excluded/HiddenTest.php');

    file_put_contents($this->tmp.'/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
            <directory suffix="Check.php">checks</directory>
            <file>explicit/ManualSpec.php</file>
            <exclude>tests/Unit/SkipMeTest.php</exclude>
            <exclude>excluded</exclude>
        </testsuite>
    </testsuites>
</phpunit>
XML);

    expect(SuiteDiscovery::discover($this->tmp))->toBe([
        realpath($this->tmp.'/checks/HealthCheck.php'),
        realpath($this->tmp.'/explicit/ManualSpec.php'),
        realpath($this->tmp.'/tests/Unit/AlphaTest.php'),
    ]);
});

it('finds default configuration files at the project root', function () {
    file_put_contents($this->tmp.'/phpunit.xml.dist', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);

    expect(SuiteDiscovery::configurationPath($this->tmp))->toBe($this->tmp.'/phpunit.xml.dist');

    file_put_contents($this->tmp.'/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Unit">
            <directory>specs</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);

    expect(SuiteDiscovery::configurationPath($this->tmp))->toBe($this->tmp.'/phpunit.xml');
});

it('resolves explicit configuration paths relative to the project root', function () {
    file_put_contents($this->tmp.'/custom.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);

    expect(SuiteDiscovery::configurationPath($this->tmp, 'custom.xml'))->toBe($this->tmp.'/custom.xml');
});

function writeDiscoveryFile(string $path): void
{
    Dirs::ensure(dirname($path));
    file_put_contents($path, '<?php');
}
