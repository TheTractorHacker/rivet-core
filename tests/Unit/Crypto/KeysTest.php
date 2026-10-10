<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use RivetCore\Crypto\InvalidKeyMaterial;
use RivetCore\Crypto\KeyFile;
use RivetCore\Crypto\KeyGenerator;
use RivetCore\Crypto\KeyPurpose;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\KeyRingLoader;
use RivetCore\Crypto\KeyUnavailable;
use RivetCore\Crypto\UnknownKeyId;

final class KeysTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rc_keys_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        rmdir($this->dir);
    }

    public function testRingValidation(): void
    {
        foreach ([str_repeat("\0", 32), str_repeat('ab', 16), 'short', random_bytes(31), random_bytes(33), str_repeat('A', 32)] as $bad) {
            try {
                KeyRing::single($bad);
                $this->fail('accepted a bad key');
            } catch (InvalidKeyMaterial) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['', 'a:b', 'has space', '-lead', str_repeat('a', 33)] as $kid) {
            try {
                KeyRing::single(random_bytes(32), $kid);
                $this->fail('accepted kid ' . $kid);
            } catch (InvalidKeyMaterial) {
                $this->addToAssertionCount(1);
            }
        }
        $k = random_bytes(32);
        $this->expectException(InvalidKeyMaterial::class);
        KeyRing::fromKeys(['a' => $k, 'b' => $k], 'a'); // the same bytes under two ids defeats rotation
    }

    public function testRingAccessorsAndImmutability(): void
    {
        $a = random_bytes(32);
        $b = random_bytes(32);
        $r = KeyRing::single($a, 'a');
        $r2 = $r->withKey($b, 'b', '2026-10-10', true);
        $this->assertSame('a', $r->activeKid());
        $this->assertSame('b', $r2->activeKid());
        $this->assertSame(['a', 'b'], $r2->kids());
        $this->assertSame($b, $r2->activeKey());
        $this->assertSame('2026-10-10', $r2->createdAt('b'));
        $this->assertSame($a, $r2->withActive('a')->activeKey());
        $this->assertSame(['b'], $r2->without('a')->kids());
        try {
            $r2->without('b');
            $this->fail();
        } catch (InvalidKeyMaterial) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(UnknownKeyId::class);
        $r->key('zzz');
    }

    public function testNoActiveKeyIsUnavailable(): void
    {
        $this->assertTrue(KeyRing::empty()->isEmpty());
        $this->expectException(KeyUnavailable::class);
        KeyRing::empty()->activeKey();
    }

    public function testDebugOutputNeverShowsKeys(): void
    {
        $k = random_bytes(32);
        ob_start();
        var_dump(KeyRing::single($k));
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString(bin2hex($k), $dump);
        $this->assertStringNotContainsString(base64_encode($k), $dump);
        $this->assertStringContainsString('k1', $dump);
    }

    public function testDerivationIsPerPurposeAndDeterministic(): void
    {
        $r = KeyRing::single(random_bytes(32));
        $s1 = $r->derive(KeyPurpose::SETTINGS)->activeKey();
        $this->assertSame($s1, $r->derive(KeyPurpose::SETTINGS)->activeKey());
        foreach ([KeyPurpose::TOTP, KeyPurpose::MEDIA_TOKEN, KeyPurpose::TRAINING_PIN, KeyPurpose::VAULT_WRAP] as $p) {
            $this->assertNotSame($s1, $r->derive($p)->activeKey());
        }
        $this->assertNotSame($r->activeKey(), $s1);
        $this->assertSame(32, strlen($s1));
        $this->expectException(InvalidKeyMaterial::class);
        $r->derive('Bad Purpose');
    }

    public function testHkdfKnownAnswerRfc5869Style(): void
    {
        // Independent check of the derivation: HKDF-SHA256(ikm=key, salt='', info='rivetcore-crypto|v1|settings', L=32) via PHP hash_hkdf vs python-style expand.
        $key = (string) hex2bin('0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20');
        $prk = hash_hmac('sha256', $key, str_repeat("\0", 32), true);
        $okm = hash_hmac('sha256', 'rivetcore-crypto|v1|settings' . "\x01", $prk, true);
        $this->assertSame(bin2hex($okm), bin2hex(KeyPurpose::derive($key, 'settings')));
    }

    public function testFingerprintMatchesTheEditionsBackupFormula(): void
    {
        $this->assertSame(substr(hash('sha256', 'rivetit-settings-key-fingerprint|v1|abc'), 0, 16), KeyGenerator::fingerprint('abc'));
        $this->assertSame('', KeyGenerator::fingerprint(''));
        $hex = bin2hex(random_bytes(32));
        $ring = KeyGenerator::ringFromLegacySettingsKey($hex);
        $this->assertSame(KeyGenerator::fingerprint($hex), $ring->fingerprint('k1'), 'a hex settings key keeps its backup fingerprint');
        $this->assertSame((string) hex2bin($hex), $ring->activeKey());
        $this->assertSame(hash('sha256', 'not hex', true), KeyGenerator::ringFromLegacySettingsKey('not hex')->activeKey());
    }

    public function testGeneratorProducesDistinctStrongKeysAndRotates(): void
    {
        $a = KeyGenerator::newRing();
        $this->assertSame(32, strlen($a->activeKey()));
        $this->assertNotNull($a->createdAt((string) $a->activeKid()));
        $b = KeyGenerator::rotate($a);
        $this->assertCount(2, $b->kids());
        $this->assertNotSame($a->activeKid(), $b->activeKid());
        $this->assertTrue($b->has((string) $a->activeKid()));
        $this->assertSame(1, preg_match(KeyRing::KID_PATTERN, KeyGenerator::kid()));
        $this->assertNotSame(KeyGenerator::key(), KeyGenerator::key());
    }

    public function testFileRoundTripAndPermissions(): void
    {
        $ring = KeyGenerator::rotate(KeyGenerator::newRing());
        $path = $this->dir . '/keys.json';
        KeyFile::write($path, $ring);
        $this->assertSame(0640, fileperms($path) & 0777);
        $res = (new KeyRingLoader())->load($path);
        $this->assertSame('file', $res->source);
        $this->assertSame($ring->activeKey(), $res->ring->activeKey());
        $this->assertSame($ring->kids(), $res->ring->kids());
        $this->assertSame([], $res->warnings);
        $this->assertSame($ring->createdAt((string) $ring->activeKid()), $res->ring->createdAt((string) $ring->activeKid()));
        $this->expectException(KeyUnavailable::class);
        KeyFile::write($path, $ring); // refuses to overwrite
    }

    public function testWorldReadableWarnsWorldWritableRefuses(): void
    {
        $path = $this->dir . '/keys.json';
        KeyFile::write($path, KeyGenerator::newRing());
        chmod($path, 0644);
        $res = (new KeyRingLoader())->load($path);
        $this->assertNotSame([], $res->warnings);
        $this->assertStringContainsString('world-readable', $res->warnings[0]);
        try {
            (new KeyRingLoader(null, true))->load($path);
            $this->fail('strict mode must refuse a world-readable key file');
        } catch (KeyUnavailable) {
            $this->addToAssertionCount(1);
        }
        chmod($path, 0666);
        $this->expectException(KeyUnavailable::class);
        (new KeyRingLoader())->load($path);
    }

    public function testWarnsWhenInsideTheWebRoot(): void
    {
        $path = $this->dir . '/keys.json';
        KeyFile::write($path, KeyGenerator::newRing());
        $res = (new KeyRingLoader(null, false, $this->dir))->load($path);
        $this->assertStringContainsString('web root', implode(' ', $res->warnings));
    }

    public function testPhpArrayFileAndEnvironmentFallback(): void
    {
        $k = random_bytes(32);
        $php = $this->dir . '/keys.php';
        file_put_contents($php, "<?php\nreturn " . var_export(['active' => 'p1', 'keys' => ['p1' => bin2hex($k)]], true) . ";\n");
        chmod($php, 0640);
        $this->assertSame($k, (new KeyRingLoader())->load($php)->ring->activeKey());

        $env = fn (array $vars) => fn (string $n): ?string => $vars[$n] ?? null;
        $res = (new KeyRingLoader(null, false, null, $env(['RIVETCORE_KEK' => bin2hex($k), 'RIVETCORE_KEK_ID' => 'e1'])))->load($this->dir . '/missing.json');
        $this->assertSame('env', $res->source);
        $this->assertSame('e1', $res->ring->activeKid());
        $this->assertNotSame([], $res->warnings);

        $json = KeyFile::encode(KeyGenerator::newRing());
        $res = (new KeyRingLoader(null, false, null, $env(['RIVETCORE_KEYRING' => $json])))->load(null);
        $this->assertSame('env', $res->source);

        $this->assertTrue((new KeyRingLoader(null, false, null, $env([])))->load(null, false)->ring->isEmpty());
        $this->expectException(KeyUnavailable::class);
        (new KeyRingLoader(null, false, null, $env([])))->load(null);
    }

    public function testABrokenFileIsNeverMaskedByTheEnvironment(): void
    {
        $path = $this->dir . '/keys.json';
        file_put_contents($path, '{not json');
        chmod($path, 0640);
        $loader = new KeyRingLoader(null, false, null, fn (string $n): ?string => $n === 'RIVETCORE_KEK' ? bin2hex(random_bytes(32)) : null);
        $this->expectException(InvalidKeyMaterial::class);
        $loader->load($path);
    }

    public function testKeyEncodings(): void
    {
        $k = random_bytes(32);
        $this->assertSame($k, KeyFile::decodeKey(bin2hex($k)));
        $this->assertSame($k, KeyFile::decodeKey('base64:' . base64_encode($k)));
        foreach (['', 'zz', str_repeat('g', 64), 'base64:!!!', bin2hex($k) . '00'] as $bad) {
            try {
                KeyFile::decodeKey($bad);
                $this->fail();
            } catch (InvalidKeyMaterial) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidKeyMaterial::class);
        KeyFile::ringFromJson('{"keys":{"a":"' . bin2hex($k) . '"},"active":"nope"}');
    }
}
