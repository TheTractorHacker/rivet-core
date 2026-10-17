<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Deploy\AllowedHostsPolicy;
use RivetCore\Rmm\Deploy\DeployJobTypes;
use RivetCore\Rmm\Deploy\DeployProtocol;
use RivetCore\Rmm\Deploy\DeployReader;
use RivetCore\Rmm\Deploy\PackageCatalog;
use RivetCore\Rmm\Deploy\SourceUrl;

/** Phase 5: the parameter contract of the software jobs, the download-address rule and the CSV, without a database. */
final class DeployContractTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function vectors(): array
    {
        $j = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/endpoint-agent/testdata/vectors/agent_software_deploy_vectors.json'), true);
        self::assertIsArray($j);

        return $j;
    }

    /** @return array<string,array{0:array<string,mixed>,1:bool}> */
    public static function installVectors(): array
    {
        $out = [];
        foreach (self::vectors()['install'] as $c) {
            $out[$c['name']] = [$c['params'], $c['valid']];
        }

        return $out;
    }

    /** @return array<string,array{0:array<string,mixed>,1:bool}> */
    public static function detectVectors(): array
    {
        $out = [];
        foreach (self::vectors()['detect'] as $c) {
            $out[$c['name']] = [$c['params'], $c['valid']];
        }

        return $out;
    }

    /** @param array<string,mixed> $params */
    #[DataProvider('installVectors')]
    public function testInstallParametersFollowTheSharedVectors(array $params, bool $valid): void
    {
        [$clean, $err] = DeployJobTypes::install($params);
        $this->assertSame($valid, $err === null, (string) $err);
        if ($valid) {
            // normalising twice changes nothing, and the result is again accepted
            [$again, $err2] = DeployJobTypes::install($clean);
            $this->assertNull($err2);
            $this->assertSame($clean, $again);
            $this->assertContains($clean['mode'], DeployProtocol::MODES);
        }
    }

    /** @param array<string,mixed> $params */
    #[DataProvider('detectVectors')]
    public function testDetectParametersFollowTheSharedVectors(array $params, bool $valid): void
    {
        [, $err] = DeployJobTypes::detect($params);
        $this->assertSame($valid, $err === null, (string) $err);
    }

    public function testTheJobTypesAreRegisteredWithTheirCapabilitiesAndTheRightGrants(): void
    {
        $r = \RivetCore\Rmm\Job\JobTypeRegistry::withDefaults();
        $detect = $r->get('software_detect');
        $install = $r->get('software_install');
        $this->assertNotNull($detect);
        $this->assertNotNull($install);
        $this->assertSame(['software:detect', false, 'rmm.software.manage'], [$detect->capability, $detect->destructive, $detect->ability]);
        $this->assertSame(['software:install', true, 'rmm.software.deploy'], [$install->capability, $install->destructive, $install->ability]);
        $this->assertSame('software:install', DeployProtocol::capabilityFor('software_install'));
        $this->assertNull(DeployProtocol::capabilityFor('collect'));
        foreach (DeployProtocol::JOB_TYPES as $t) {
            $this->assertLessThanOrEqual(20, strlen($t));
        }
    }

    // ------------------------------------------------------------------ addresses

    /** @return array<string,array{0:string,1:?string}> */
    public static function urls(): array
    {
        return [
            'plain' => ['https://downloads.example.com/a.msi', 'downloads.example.com'],
            'upper case host' => ['https://Downloads.EXAMPLE.com/a.msi', 'downloads.example.com'],
            'default port' => ['https://a.example:443/x', 'a.example'],
            'no path' => ['https://a.example', 'a.example'],
            'query with at sign' => ['https://a.example?x=@evil.test/', 'a.example'],
            'http' => ['http://a.example/', null],
            'other port' => ['https://a.example:8080/', null],
            'userinfo' => ['https://u@a.example/', null],
            'ipv4' => ['https://1.2.3.4/', null],
            'ipv6' => ['https://[::1]/', null],
            'numeric last label' => ['https://a.b.1/', null],
            'backslash' => ['https://a.example\\@b.example/', null],
            'fragment' => ['https://a.example/#x', null],
            'space' => ['https://a.example/a b', null],
            'newline at the end' => ["https://a.example/\n", null],
            'empty' => ['', null],
            'one label' => ['https://localhost/', null],
            'trailing dot' => ['https://a.example./', null],
            'underscore host' => ['https://a_b.example/', null],
            'hyphen first' => ['https://-a.example/', null],
            'non ascii' => ["https://\u{e9}.example/", null],
        ];
    }

    #[DataProvider('urls')]
    public function testTheAddressReaderIsStrict(string $url, ?string $host): void
    {
        $this->assertSame($host, SourceUrl::host($url));
        $n = SourceUrl::normalise($url);
        if ($host === null) {
            $this->assertNull($n);
        } else {
            $this->assertNotNull($n);
            $this->assertSame($host, SourceUrl::host($n));
            $this->assertSame($n, SourceUrl::normalise($n), 'normalising is idempotent');
        }
    }

    public function testThePolicyIsExactAndFailsClosed(): void
    {
        $p = new AllowedHostsPolicy(static fn (): array => ['a.example']);
        $this->assertTrue($p->allows('https://A.example/x', 1));
        $this->assertFalse($p->allows('https://b.a.example/x', 1));
        $this->assertFalse($p->allows('https://a.example.evil.test/x', 1));
        $none = new AllowedHostsPolicy(static fn (): array => []);
        $this->assertFalse($none->allows('https://a.example/x', 1), 'an empty list allows nothing');
        $broken = new AllowedHostsPolicy(static function (): array {
            throw new \RuntimeException('database is down');
        });
        $this->assertFalse($broken->allows('https://a.example/x', 1), 'when the list cannot be read nothing is downloaded');
    }

    public function testAVersionIsCheckedWithTheSourcePolicyAsWell(): void
    {
        $allow = new AllowedHostsPolicy(static fn (): array => ['a.example']);
        $in = ['version' => '1', 'os' => 'windows', 'fmt' => 'msi', 'url' => 'https://a.example/x.msi', 'sha256' => strtoupper(str_repeat('ab', 32)), 'size_bytes' => 5, 'product_code' => '{aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa}'];
        $row = PackageCatalog::validateVersion($in, $allow);
        $this->assertSame(str_repeat('ab', 32), $row['sha256'], 'the hash is stored in lower case');
        $this->assertSame('{AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA}', $row['product_code']);
        $this->expectException(\InvalidArgumentException::class);
        PackageCatalog::validateVersion(['url' => 'https://b.example/x.msi'] + $in, $allow);
    }

    public function testArgumentRules(): void
    {
        foreach (['', '/S', '/S /norestart', '/VERYSILENT /DIR=C:\\Tools\\App', 'a=1,2'] as $ok) {
            $this->assertTrue(DeployProtocol::argsOk($ok), $ok);
        }
        foreach (['/S "x"', '/S&x', '/S;x', "/S\n", ' /S', '/S ', '/S  /x', '%TEMP%', '$x', '`x`', '(x)', '/S|x', '<x', '>x', "\0"] as $bad) {
            $this->assertFalse(DeployProtocol::argsOk($bad), json_encode($bad));
        }
    }

    // ------------------------------------------------------------------ csv

    public function testCsvEscapesQuotesAndNeutralisesFormulas(): void
    {
        $csv = DeployReader::toCsv(['rows' => [['host' => '=HYPERLINK("http://evil")', 'n' => 5, 'neg' => -3, 'note' => 'a,b', 'q' => 'say "hi"', 'plus' => '+1 555', 'at' => '@x', 'tab' => "\tx", 'ok' => true, 'null' => null, 'dash' => '-cmd']]]);
        $lines = explode("\r\n", $csv);
        $this->assertSame('host,n,neg,note,q,plus,at,tab,ok,null,dash', $lines[0]);
        $this->assertSame('"\'=HYPERLINK(""http://evil"")",5,-3,"a,b","say ""hi""",\'+1 555,\'@x,\'' . "\tx" . ',yes,,\'-cmd', $lines[1]);
        $this->assertSame('', DeployReader::toCsv(['rows' => []]));
    }
}
