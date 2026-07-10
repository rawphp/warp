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

it('discovers phpunit.dist.xml when it is the only configuration file present', function () {
    file_put_contents($this->tmp.'/phpunit.dist.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);

    expect(SuiteDiscovery::configurationPath($this->tmp))->toBe($this->tmp.'/phpunit.dist.xml');
});

it('prefers phpunit.dist.xml over phpunit.xml.dist, matching PHPUnit precedence', function () {
    file_put_contents($this->tmp.'/phpunit.xml.dist', discoveryConfigFixture());
    file_put_contents($this->tmp.'/phpunit.dist.xml', discoveryConfigFixture());

    expect(SuiteDiscovery::configurationPath($this->tmp))->toBe($this->tmp.'/phpunit.dist.xml');
});

it('prefers phpunit.xml over both dist variants, matching PHPUnit precedence', function () {
    file_put_contents($this->tmp.'/phpunit.xml.dist', discoveryConfigFixture());
    file_put_contents($this->tmp.'/phpunit.dist.xml', discoveryConfigFixture());
    file_put_contents($this->tmp.'/phpunit.xml', discoveryConfigFixture());

    expect(SuiteDiscovery::configurationPath($this->tmp))->toBe($this->tmp.'/phpunit.xml');
});

it('asserts the probe order matches the vendored PHPUnit XmlConfigurationFileFinder candidates', function () {
    // Live parity check: this reads PHPUnit's own vendored source rather than a
    // hardcoded copy, so a composer bump that reorders/renames candidates in
    // vendor/phpunit/phpunit/src/TextUI/Configuration/Cli/XmlConfigurationFileFinder.php
    // fails this test and forces a review of SuiteDiscovery::configurationPath().
    $source = file_get_contents(
        dirname(__DIR__, 3).'/vendor/phpunit/phpunit/src/TextUI/Configuration/Cli/XmlConfigurationFileFinder.php'
    );

    preg_match_all('/\$directory \. \'\/(phpunit[\w.]*)\'/', $source, $matches);

    expect($matches[1])->toBe(['phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist']);
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

it('treats an absolute explicit configuration path as already resolved, unchanged (REQ-107)', function () {
    file_put_contents($this->tmp.'/custom.xml', discoveryConfigFixture());

    expect(SuiteDiscovery::configurationPath($this->tmp, $this->tmp.'/custom.xml'))
        ->toBe($this->tmp.'/custom.xml');
});

it('no longer has a private resolve copy on SuiteDiscovery (deleted duplicate, REQ-107, uses shared Paths::absolute)', function () {
    expect(method_exists(SuiteDiscovery::class, 'resolve'))->toBeFalse();
});

function writeDiscoveryFile(string $path): void
{
    Dirs::ensure(dirname($path));
    file_put_contents($path, '<?php');
}

function discoveryConfigFixture(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML;
}
