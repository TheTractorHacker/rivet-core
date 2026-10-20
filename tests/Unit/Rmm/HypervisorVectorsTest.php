<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Hypervisor\HypervisorProtocol;
use RivetCore\Rmm\Hypervisor\HypervisorService;

/** The hypervisor vector file (read by the Go agent too): the signed configuration and the server verdict on every check-in block. */
final class HypervisorVectorsTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function vectors(): array
    {
        $d = json_decode((string) file_get_contents(RmmVectors::dir() . '/agent_hypervisor_vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('RIVETIT-HYPERVISOR-v1', $d['format']);

        return $d;
    }

    public function testTheSignedConfigurationVerifiesAndAChangedCharacterBreaksIt(): void
    {
        $v = self::vectors();
        $cfg = $v['signed_config']['config'];
        $this->assertSame(HypervisorProtocol::SIGN_DOMAIN, $v['signed_config']['domain']);
        $this->assertSame($v['signed_config']['message'], Signer::hypervisorConfigMessage($cfg));
        $this->assertTrue(Signer::verify($v['signed_config']['message'], $cfg['signature'], $v['test_key']['public_key_base64']));
        $this->assertSame(substr(hash('sha256', Signer::hypervisorConfigMessage(array_diff_key($cfg, ['version' => 1, 'signature' => 1]))), 0, 16), $cfg['version']);
        foreach (['platform' => 'windows', 'full_interval_s' => 601, 'seq' => 1, 'max_vms' => 1] as $k => $val) {
            $t = $cfg;
            $t[$k] = $val;
            $this->assertFalse(Signer::verify(Signer::hypervisorConfigMessage($t), $cfg['signature'], $v['test_key']['public_key_base64']), $k);
        }
        $this->assertLessThan(HypervisorProtocol::CONFIG_MAX_BYTES, strlen((string) json_encode($cfg)));
    }

    public function testTheServerVerdictOnEveryReportIsTheOneInTheFile(): void
    {
        $v = self::vectors();
        foreach ($v['reports'] as $case) {
            $c = HypervisorService::cleanReport(json_decode((string) json_encode($case['input']), true), (int) $v['now']);
            $e = $case['expect'];
            $this->assertNotNull($c, $case['name']);
            $this->assertSame($e['mode'], $c['mode'], $case['name']);
            $this->assertSame($e['vms'], array_column($c['vms'], 'key'), $case['name']);
            $this->assertSame($e['storage'], array_column($c['storage'], 'name'), $case['name']);
            $this->assertSame($e['changes'], array_column($c['changes'], 'key'), $case['name']);
            $this->assertSame($e['error'], $c['error'], $case['name']);
            $this->assertSame($e['version_length'], strlen($c['version']), $case['name']);
            if ($case['agent_shaped']) {
                $this->assertSame(count($case['input']['vms'] ?? []), count($c['vms']), $case['name'] . ': an agent-shaped report loses no VM');
            }
        }
        $names = array_column($v['reports'], 'name');
        $this->assertContains('hostile', $names);
    }
}
