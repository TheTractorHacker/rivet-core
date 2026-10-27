<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Installer\InstallerStamp as S;

final class InstallerStampTest extends TestCase
{
    /** @return array<string,mixed> */
    private function vec(): array
    {
        return RmmVectors::load('agent_installer_trailer_vectors.json');
    }

    public function testFixtureConstantsMatchTheClass(): void
    {
        $j = $this->vec();
        $this->assertSame(S::FOOTER_LEN, $j['footer_length']);
        $this->assertSame(S::MAX_PAYLOAD, $j['max_payload']);
        $this->assertSame(S::MAGIC, $j['format']);
        $this->assertSame(52, S::FOOTER_LEN);
        $this->assertSame(16384, S::MAX_PAYLOAD);
        $this->assertSame('RIVETIT-EMBED-v1', S::MAGIC);
    }

    public function testPositiveVectors(): void
    {
        $j = $this->vec();
        $this->assertCount(7, $j['vectors']);
        foreach ($j['vectors'] as $v) {
            $exe = (string) hex2bin($v['exe_hex']);
            $stamped = S::stamp($exe, $v['payload']);
            $this->assertSame($v['stamped_hex'], bin2hex($stamped), $v['name']);
            $this->assertSame($v['footer_hex'], bin2hex(S::footer($v['payload'])), $v['name']);
            $this->assertSame(52, strlen(S::footer($v['payload'])));
            $this->assertSame($v['payload'] . S::footer($v['payload']), S::trailer($v['payload']));
            $this->assertSame($exe, substr($stamped, 0, strlen($exe)), $v['name'] . ' exe first');
            $r = S::read($stamped);
            $this->assertNotNull($r, $v['name']);
            $this->assertSame($v['payload'], $r['payload']);
            $this->assertSame(strlen($exe), $r['exe_length']);
            $this->assertSame(hash('sha256', $v['payload']), $v['payload_sha256_hex']);
        }
    }

    public function testFourteenNegativeVectors(): void
    {
        $j = $this->vec();
        $this->assertCount(14, $j['negative']);
        foreach ($j['negative'] as $n) {
            $this->assertSame('reject', $n['expect']);
            $this->assertNull(S::read((string) hex2bin($n['stamped_hex'])), $n['name'] . ': ' . $n['reason']);
        }
    }

    public function testMagicInsideBodyIsNotAFooter(): void
    {
        $this->assertFalse(S::hasFooter('MZ....' . S::MAGIC . '....body'));
        $this->assertTrue(S::hasFooter('anything' . S::MAGIC));
        $this->assertFalse(S::hasFooter('short'));
    }

    public function testBuildPayloadFields(): void
    {
        $p = S::buildPayload(['installer_id' => 'u', 'server_url' => 'https://h/x', 'enrollment_token' => 't', 'department' => 'Dépt "A"', 'ca_pem' => null, 'created_at' => 'c', 'expires_at' => 'e']);
        $d = json_decode($p, true);
        $this->assertSame(['version', 'installer_id', 'server_url', 'enrollment_token', 'department', 'ca_pem', 'created_at', 'expires_at'], array_keys($d));
        $this->assertSame(1, $d['version']);
        $this->assertNull($d['ca_pem']);
        $this->assertSame('Dépt "A"', $d['department']);
        $this->assertStringNotContainsString('\/', $p);
        $this->assertStringNotContainsString('\\u00e9', $p);
        $d2 = json_decode(S::buildPayload(['installer_id' => 'u', 'server_url' => 's', 'enrollment_token' => 't', 'department' => 'd', 'ca_pem' => '', 'created_at' => 'c', 'expires_at' => 'e']), true);
        $this->assertNull($d2['ca_pem'], 'empty CA is null');
    }

    public function testBoundaries(): void
    {
        $f = ['installer_id' => 'u', 'server_url' => 's', 'enrollment_token' => 't', 'created_at' => 'c', 'expires_at' => 'e'];
        $base = strlen(S::buildPayload($f + ['department' => '']));
        $exact = S::buildPayload($f + ['department' => str_repeat('x', 16384 - $base)]);
        $this->assertSame(16384, strlen($exact));
        $this->assertNotNull(S::read(S::stamp('exe', $exact)));
        $this->expectException(\LengthException::class);
        S::buildPayload($f + ['department' => str_repeat('x', 16385 - $base)]);
    }

    public function testFooterRefusesEmptyAndOversize(): void
    {
        foreach (['', str_repeat('a', 16385)] as $bad) {
            try {
                S::footer($bad);
                $this->fail('expected LengthException');
            } catch (\LengthException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testShortFilesAndDoubleStamp(): void
    {
        $this->assertNull(S::read(str_repeat("\0", 51)));
        $this->assertNull(S::read(''));
        $restamp = S::stamp(S::stamp('EXE', '{"a":1}'), '{"b":2}');
        $r = S::read($restamp);
        $this->assertNotNull($r);
        $this->assertSame(['b' => 2], $r['data'], 'the LAST footer wins');
    }
}
