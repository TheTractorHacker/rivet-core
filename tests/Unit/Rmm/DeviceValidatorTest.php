<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Enrollment\DeviceValidator as V;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\RmmProtocol;

final class DeviceValidatorTest extends TestCase
{
    /** @return array<string,mixed> */
    private function dev(array $over = []): array
    {
        return $over + [
            'install_id' => '3F2B8C1E-5D4A-4E7B-9A10-6C2D8E9F0A1B', 'machine_guid' => 'AbCdEf0123456789', 'hostname' => "WS-ONE\n", 'os' => 'windows',
            'os_version' => 'Windows 11 23H2', 'arch' => 'amd64', 'serial' => ' SER-1 ', 'manufacturer' => 'Dell', 'model' => 'Latitude',
            'mac_addresses' => ['AA-BB-CC-00-00-01', 'aa:bb:cc:00:00:01', '00:00:00:00:00:00', 'zz', 5], 'agent_version' => '0.1.0-beta.2',
        ];
    }

    public function testJunkSerialListIsFrozen(): void
    {
        $this->assertCount(16, V::JUNK_SERIALS);
        $this->assertSame(RmmProtocol::JUNK_SERIALS, V::JUNK_SERIALS);
        $this->assertSame('default', V::JUNK_SERIALS[15]);
    }

    public function testNormalizeMac(): void
    {
        $this->assertSame('aa:bb:cc:00:00:01', V::normalizeMac('AA-BB-CC-00-00-01'));
        $this->assertSame('aa:bb:cc:00:00:01', V::normalizeMac("  aa:bb:cc:00:00:01 \n"));
        $this->assertNull(V::normalizeMac('00:00:00:00:00:00'));
        $this->assertNull(V::normalizeMac('00-00-00-00-00-00'));
        foreach (['', 'aabbcc000001', 'aa:bb:cc:00:00', 'aa:bb:cc:00:00:01:02', 'gg:bb:cc:00:00:01', 'aa.bb.cc.00.00.01'] as $bad) {
            $this->assertNull(V::normalizeMac($bad), $bad);
        }
    }

    public function testCleanSerial(): void
    {
        $this->assertNull(V::cleanSerial(null));
        $this->assertSame('SER-1', V::cleanSerial('  SER-1 '));
        foreach (V::JUNK_SERIALS as $junk) {
            $this->assertNull(V::cleanSerial($junk), "junk '$junk'");
            $this->assertNull(V::cleanSerial('  ' . strtoupper($junk) . ' '), "junk upper '$junk'");
        }
        $this->assertSame('0001', V::cleanSerial('0001'));
        $this->assertSame('none-real', V::cleanSerial('none-real'));
    }

    public function testCleanText(): void
    {
        $this->assertNull(V::cleanText(null, 10));
        $this->assertNull(V::cleanText(5, 10));
        $this->assertNull(V::cleanText("  \n\t ", 10));
        $this->assertSame('a b c', V::cleanText("a\x00\x01b\x7fc", 10));
        $this->assertSame('a b', V::cleanText("a\r\n\tb", 10));
        $this->assertSame('abcde', V::cleanText('abcdefgh', 5));
        $this->assertSame('éééé', V::cleanText('éééééé', 4));
        $this->assertSame('x', V::cleanText(" x ", 5));
        $this->assertNull(V::cleanText("\xff\xfe", 5), 'invalid UTF-8 collapses to nothing');
    }

    public function testValidateDeviceNormalises(): void
    {
        $out = V::validateDevice($this->dev());
        $this->assertSame([
            'install_id' => '3f2b8c1e-5d4a-4e7b-9a10-6c2d8e9f0a1b',
            'machine_guid' => 'abcdef0123456789',
            'hostname' => 'WS-ONE',
            'os_version' => 'Windows 11 23H2',
            'arch' => 'amd64',
            'serial' => 'SER-1',
            'manufacturer' => 'Dell',
            'model' => 'Latitude',
            'macs' => ['aa:bb:cc:00:00:01'],
            'agent_version' => '0.1.0-beta.2',
        ], $out);
    }

    public function testOptionalFields(): void
    {
        $out = V::validateDevice(['install_id' => '3f2b8c1e-5d4a-4e7b-9a10-6c2d8e9f0a1b', 'hostname' => 'h', 'os' => 'windows', 'arch' => 'arm64', 'agent_version' => '1.2.3']);
        $this->assertNull($out['machine_guid']);
        $this->assertNull($out['serial']);
        $this->assertNull($out['manufacturer']);
        $this->assertNull($out['model']);
        $this->assertSame('', $out['os_version']);
        $this->assertSame([], $out['macs']);
        $out = V::validateDevice($this->dev(['serial' => 'To Be Filled By O.E.M.']));
        $this->assertNull($out['serial']);
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function invalid(): array
    {
        return [
            'install_id missing' => [['install_id' => null], 'install_id'],
            'install_id format' => [['install_id' => 'nope'], 'install_id'],
            'install_id type' => [['install_id' => 5], 'install_id'],
            'guid chars' => [['machine_guid' => 'bad guid!'], 'machine_guid'],
            'guid long' => [['machine_guid' => str_repeat('a', 65)], 'machine_guid'],
            'guid empty' => [['machine_guid' => ''], 'machine_guid'],
            'guid type' => [['machine_guid' => 7], 'machine_guid'],
            'hostname empty' => [['hostname' => " \n"], 'hostname'],
            'hostname missing' => [['hostname' => null], 'hostname'],
            'os plan9' => [['os' => 'plan9'], 'os'],
            'os linux by default' => [['os' => 'linux'], 'os'],
            'arch sparc' => [['arch' => 'sparc'], 'arch'],
            'arch type' => [['arch' => ['amd64']], 'arch'],
            'version short' => [['agent_version' => '1.2'], 'agent_version'],
            'version v' => [['agent_version' => 'v1.2.3'], 'agent_version'],
            'version type' => [['agent_version' => 123], 'agent_version'],
            'macs not list' => [['mac_addresses' => 'aa'], 'mac_addresses'],
            'macs too many' => [['mac_addresses' => array_fill(0, 33, 'aa:bb:cc:00:00:01')], 'mac_addresses'],
        ];
    }

    /** @param array<string,mixed> $over */
    #[DataProvider('invalid')]
    public function testInvalidFields(array $over, string $field): void
    {
        try {
            V::validateDevice($this->dev($over));
            $this->fail('expected ApiError');
        } catch (ApiError $e) {
            $this->assertSame(422, $e->http);
            $this->assertSame('invalid', $e->errCode);
            $this->assertSame("device.$field is invalid", $e->getMessage());
        }
    }

    public function testLinuxOnlyWhenAllowed(): void
    {
        $this->assertSame('amd64', V::validateDevice($this->dev(['os' => 'linux']), true)['arch']);
        $this->expectException(ApiError::class);
        V::validateDevice($this->dev(['os' => 'darwin']), true);
    }

    public function testThirtyTwoMacsAreAccepted(): void
    {
        $macs = [];
        for ($i = 1; $i <= 32; $i++) {
            $macs[] = sprintf('aa:bb:cc:00:00:%02x', $i);
        }
        $this->assertCount(32, V::validateDevice($this->dev(['mac_addresses' => $macs]))['macs']);
    }
}
