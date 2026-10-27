<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\Crypto\CanonicalJson;
use RivetCore\Rmm\Crypto\Signer;

final class SignerTest extends TestCase
{
    /** @return array<string,mixed> */
    private function vec(): array
    {
        return RmmVectors::load('agent_job_signing_vectors.json');
    }

    public function testTestKeyDerivesFromTheFixedSeed(): void
    {
        $v = $this->vec();
        $seed = hash('sha256', 'RivetIT-agent-TEST-seed', true);
        $this->assertSame($v['test_key']['seed_hex'], bin2hex($seed));
        [$pub, $sec] = Signer::keypairFromSeed($seed);
        $this->assertSame($v['test_key']['public_key_base64'], $pub);
        $this->assertSame($v['test_key']['secret_key_base64_libsodium'], $sec);
    }

    public function testAllFourJobVectors(): void
    {
        $v = $this->vec();
        $pub = $v['test_key']['public_key_base64'];
        $sec = $v['test_key']['secret_key_base64_libsodium'];
        $this->assertCount(4, $v['jobs']);
        foreach ($v['jobs'] as $job) {
            $canon = CanonicalJson::encode(json_decode($job['job_json']));
            $this->assertSame($job['canonical'], $canon, $job['name']);
            $this->assertTrue(Signer::verify($canon, $job['signature'], $pub), $job['name']);
            $this->assertSame($job['signature'], Signer::sign($canon, $sec), $job['name'] . ' (deterministic)');
            $this->assertSame($canon, Signer::jobMessage((array) json_decode($job['job_json'])), $job['name'] . ' via jobMessage');
            $withSig = (array) json_decode($job['job_json']);
            $withSig['signature'] = 'ignored';
            $this->assertSame($canon, Signer::jobMessage($withSig), $job['name'] . ' signature member excluded');
            $this->assertFalse(Signer::verify($canon . ' ', $job['signature'], $pub), $job['name'] . ' one byte changed');
        }
    }

    public function testUpdateManifestSignsTheHexTextNotTheBytes(): void
    {
        $v = $this->vec();
        $pub = $v['test_key']['public_key_base64'];
        $sec = $v['test_key']['secret_key_base64_libsodium'];
        $m = $v['update_manifest'];
        $this->assertSame(hash('sha256', 'RivetIT test package v1.2.3'), $m['sha256']);
        $this->assertTrue(Signer::verifyManifest($m['sha256'], $m['signature'], $pub));
        $this->assertSame($m['signature'], Signer::signManifest($m['sha256'], $sec));
        $this->assertFalse(Signer::verifyManifest((string) hex2bin($m['sha256']), $m['signature'], $pub), 'raw digest bytes are not what is signed');
        $this->assertFalse(Signer::verifyManifest(strtoupper($m['sha256']), $m['signature'], $pub), 'hex case matters');
    }

    public function testCheckDefinitionSignature(): void
    {
        $v = $this->vec();
        $pub = $v['test_key']['public_key_base64'];
        $sec = $v['test_key']['secret_key_base64_libsodium'];
        $c = $v['check_definition'];
        $this->assertTrue(Signer::verify($c['canonical'], $c['signature'], $pub));
        $item = json_decode($c['check_json'], true);
        $this->assertSame($c['canonical'], Signer::checkMessage($item));
        $this->assertSame($c['signature'], Signer::sign(Signer::checkMessage($item), $sec));
        $item['signature'] = 'x';
        $this->assertSame($c['canonical'], Signer::checkMessage($item), 'signature member excluded');
        $empty = ['key' => 'pending_reboot', 'type' => 'pending_reboot', 'params' => [], 'interval_s' => 3600];
        $this->assertSame('{"interval_s":3600,"key":"pending_reboot","params":{},"type":"pending_reboot"}', Signer::checkMessage($empty), 'empty params sign as {}');
    }

    public function testVerifyRejectsMalformedInput(): void
    {
        $v = $this->vec();
        $pub = $v['test_key']['public_key_base64'];
        $sig = $v['jobs'][0]['signature'];
        $msg = $v['jobs'][0]['canonical'];
        $this->assertTrue(Signer::verify($msg, $sig, $pub));
        $this->assertFalse(Signer::verify($msg, '!!!', $pub));
        $this->assertFalse(Signer::verify($msg, base64_encode('short'), $pub));
        $this->assertFalse(Signer::verify($msg, $sig, 'not base64 !'));
        $this->assertFalse(Signer::verify($msg, $sig, base64_encode('short')));
        [$otherPub] = Signer::generateKeypair();
        $this->assertFalse(Signer::verify($msg, $sig, $otherPub));
    }

    public function testSignRefusesBadKeys(): void
    {
        foreach (['', '!!!', base64_encode('short')] as $bad) {
            try {
                Signer::sign('m', $bad);
                $this->fail('expected exception');
            } catch (\RuntimeException $e) {
                $this->assertSame('invalid signing key', $e->getMessage());
            }
        }
    }

    public function testGeneratedKeypairAndKeyId(): void
    {
        [$pub, $sec] = Signer::generateKeypair();
        $this->assertSame(32, strlen((string) base64_decode($pub, true)));
        $this->assertSame(64, strlen((string) base64_decode($sec, true)));
        $this->assertTrue(Signer::verify('hello', Signer::sign('hello', $sec), $pub));
        $this->assertSame(substr(hash('sha256', $pub), 0, 16), Signer::keyId($pub), 'hash over the base64 text');
        $this->assertSame(16, strlen(Signer::keyId($pub)));
        $this->assertSame(substr(hash('sha256', 'AAAA'), 0, 16), Signer::keyId('AAAA'));
        $this->assertNotSame(substr(hash('sha256', (string) base64_decode($pub, true)), 0, 16), Signer::keyId($pub));
    }

    public function testStoredKeyRoundTripsThroughTheSecretBox(): void
    {
        $box = new class implements SecretBoxInterface {
            public function encrypt(string $plaintext): string
            {
                return 'ENC2:' . strrev($plaintext);
            }

            public function decrypt(string $ciphertext): string
            {
                return str_starts_with($ciphertext, 'ENC2:') ? strrev(substr($ciphertext, 5)) : '';
            }
        };
        $stored = Signer::generateStoredKey($box);
        $this->assertSame(['signing_key_id', 'signing_public_key', 'signing_private_key_enc'], array_keys($stored));
        $this->assertSame(Signer::keyId($stored['signing_public_key']), $stored['signing_key_id']);
        $this->assertStringStartsWith('ENC2:', $stored['signing_private_key_enc']);
        $sec = Signer::openSecretKey($box, $stored['signing_private_key_enc']);
        $this->assertTrue(Signer::verify('m', Signer::sign('m', $sec), $stored['signing_public_key']));
        foreach (['', 'garbage', 'ENC2:' . strrev('not-a-key')] as $bad) {
            try {
                Signer::openSecretKey($box, $bad);
                $this->fail('expected exception');
            } catch (\RuntimeException $e) {
                $this->assertSame('endpoint agent signing key is not available', $e->getMessage());
            }
        }
    }
}
