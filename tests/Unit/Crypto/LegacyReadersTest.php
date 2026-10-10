<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Crypto\DecryptionFailed;
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\KeyUnavailable;
use RivetCore\Crypto\LegacyOtpReader;
use RivetCore\Crypto\LegacyReaders;
use RivetCore\Crypto\LegacySettingsCbcReader;
use RivetCore\Crypto\LegacySettingsGcmReader;
use RivetCore\Crypto\LegacyUserKeyReader;
use RivetCore\Crypto\LegacyVaultFieldReader;
use RivetCore\Crypto\PlaintextPassthroughReader;
use RivetCore\Crypto\VaultCipher;
use RivetCore\Crypto\VaultKeyWrap;

require_once __DIR__ . '/../../Fixtures/crypto/LegacyEditionFunctions.php';

use RivetCore\Tests\Fixtures\LegacyEditions\LegacyEditionFunctions as Editions;

/**
 * The Core readers against output of the editions' own functions: tests/Fixtures/crypto/legacy_samples.json holds values written by
 * the copied function bodies (generate.php), and the live tests below produce fresh ones in the same run.
 */
final class LegacyReadersTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function samples(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/crypto/legacy_samples.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,array{array<string,string>}> */
    public static function golden(): array
    {
        $out = [];
        foreach (self::samples()['samples'] as $i => $s) {
            $out[$s['format'] . ' #' . $i] = [$s];
        }

        return $out;
    }

    private function envelopeFor(string $format): Envelope
    {
        $f = self::samples();
        $readers = match ($format) {
            'ENC2', 'ENC' => LegacyReaders::settings($f['settings_key']),
            'enc' => [new LegacyOtpReader($f['master_key'])],
            'vault-cbc' => [new LegacyVaultFieldReader($f['master_key'])],
            'V2' => [new LegacyUserKeyReader($f['password'])],
            'V1' => [new LegacyUserKeyReader($f['password'], true)],
        };

        return new Envelope(KeyRing::single(random_bytes(32)), $readers);
    }

    /** @param array<string,string> $sample */
    #[DataProvider('golden')]
    public function testGoldenSamplesFromTheEditionsOpen(array $sample): void
    {
        $e = $this->envelopeFor($sample['format']);
        $this->assertSame($sample['plaintext'], $e->open($sample['stored'], 'any:context'));
        $this->assertTrue($e->needsRewrap($sample['stored']));
        $this->assertStringStartsWith('legacy:', $e->label($sample['stored']));
    }

    /** @param array<string,string> $sample */
    #[DataProvider('golden')]
    public function testGoldenSamplesRoundTripThroughTheEditionsOwnReaders(array $sample): void
    {
        $f = self::samples();
        $s = $sample['stored'];
        $got = match ($sample['format']) {
            'ENC2', 'ENC' => Editions::decryptSetting($s, $f['settings_key']),
            'enc' => Editions::decryptOtpSecret($s, $f['master_key']),
            'vault-cbc' => Editions::decryptCredentialEntryWithKey($s, $f['master_key']),
            default => Editions::decryptUserSpecificKey($s, $f['password']),
        };
        $this->assertSame($sample['plaintext'], $got, 'the fixture must be what the editions themselves read');
    }

    public function testFixtureKeyShape(): void
    {
        $f = self::samples();
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $f['settings_key']));
        $this->assertSame(1, preg_match('/^[A-Za-z0-9_-]{16}$/', $f['master_key']));
    }

    public function testFreshEditionOutputIsReadByCore(): void
    {
        $key = bin2hex(random_bytes(32));
        $master = Editions::randomString();
        foreach (['a', '0', "multi\nline", str_repeat('é', 100)] as $p) {
            $this->assertSame($p, (new LegacySettingsGcmReader($key))->read(Editions::encryptSetting($p, $key), 'x'));
            $this->assertSame($p, (new LegacySettingsCbcReader($key))->read(Editions::encryptSettingCbc($p, $key), 'x'));
            $this->assertSame($p, (new LegacyVaultFieldReader($master))->read(Editions::encryptCredentialEntryWithKey($p, $master), 'x'));
        }
        $this->assertSame('JBSWY3DPEHPK3PXP', (new LegacyOtpReader($master))->read(Editions::encryptOtpSecret('JBSWY3DPEHPK3PXP', $master), 'x'));
    }

    public function testWrongKeyFailsClosedInsteadOfReturningEmpty(): void
    {
        $key = bin2hex(random_bytes(32));
        $enc2 = Editions::encryptSetting('secret', $key);
        $this->expectException(DecryptionFailed::class);
        (new LegacySettingsGcmReader(bin2hex(random_bytes(32))))->read($enc2, 'x');
    }

    public function testMissingLegacyKeyIsKeyUnavailable(): void
    {
        $this->expectException(KeyUnavailable::class);
        (new LegacySettingsGcmReader(''))->read('ENC2:AAAA', 'x');
    }

    public function testMalformedLegacyValuesAreDecryptionFailed(): void
    {
        $k = bin2hex(random_bytes(32));
        foreach ([
            [new LegacySettingsGcmReader($k), 'ENC2:'],
            [new LegacySettingsGcmReader($k), 'ENC2:!!!'],
            [new LegacySettingsGcmReader($k), 'ENC2:' . base64_encode('short')],
            [new LegacySettingsCbcReader($k), 'ENC:'],
            [new LegacySettingsCbcReader($k), 'ENC:' . base64_encode(str_repeat('a', 32))],
            [new LegacyOtpReader('masterkey1234567'), 'enc:abc'],
            [new LegacyVaultFieldReader('masterkey1234567'), str_repeat('A', 16) . str_repeat('B', 24)],
            [new LegacyUserKeyReader('pw'), 'V2:' . str_repeat('x', 40)],
        ] as [$reader, $value]) {
            try {
                $reader->read($value, 'x');
                $this->fail('read ' . $reader->name() . ' garbage');
            } catch (DecryptionFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUserKeyWrongPasswordIsRefused(): void
    {
        $f = self::samples();
        $v2 = Editions::setupFirstUserSpecificKey($f['password'], $f['master_key']);
        $refused = 0;
        for ($i = 0; $i < 20; $i++) {
            try {
                (new LegacyUserKeyReader('wrong password ' . $i))->read($v2, 'x');
            } catch (DecryptionFailed) {
                $refused++;
            }
        }
        $this->assertSame(20, $refused, 'a wrong password must never yield a key (padding or printable check)');
    }

    public function testVaultMigrationHelperConvertsAndBindsToTheCredential(): void
    {
        $master = Editions::randomString();
        $legacy = Editions::encryptCredentialEntryWithKey('s3cret!', $master);
        $cipher = new VaultCipher(VaultKeyWrap::generateDek());
        $this->assertTrue(VaultCipher::isLegacy($legacy));
        $v3 = $cipher->migrate($legacy, 42, $master, 'password');
        $this->assertStringStartsWith('v3:dek:', $v3);
        $this->assertSame('s3cret!', $cipher->open($v3, 42, 'password'));
        $this->assertSame($v3, $cipher->migrate($v3, 42, $master, 'password'), 'already v3: unchanged');
        $this->assertSame('', $cipher->migrate('', 42, $master));
        $this->expectException(DecryptionFailed::class);
        $cipher->open($v3, 43, 'password');
    }

    public function testLegacyUserWrapOpensThroughTheHelper(): void
    {
        $f = self::samples();
        $v2 = Editions::setupFirstUserSpecificKey($f['password'], $f['master_key']);
        $this->assertSame($f['master_key'], VaultKeyWrap::openLegacyUserKey($v2, $f['password']));
        $this->expectException(DecryptionFailed::class);
        VaultKeyWrap::openLegacyUserKey('not legacy', $f['password']);
    }

    public function testPlaintextPassthroughIsOptInAndNeverMatchesEncryptedFormats(): void
    {
        $r = new PlaintextPassthroughReader();
        foreach (['v3:k1:AAAA', 'ENC2:x', 'ENC:x', 'enc:x', 'V2:x'] as $v) {
            $this->assertFalse($r->supports($v));
        }
        $this->assertTrue($r->supports('hunter2'));
        $e = new Envelope(KeyRing::single(random_bytes(32)));
        $this->expectException(DecryptionFailed::class);
        $e->open('hunter2', 'c'); // without the reader a cleartext value is refused, not echoed
    }

    public function testPlaintextReaderWhenRegisteredReturnsAndFlagsTheValue(): void
    {
        $e = new Envelope(KeyRing::single(random_bytes(32)), LegacyReaders::plaintext());
        $this->assertSame('hunter2', $e->open('hunter2', 'c'));
        $this->assertTrue($e->needsRewrap('hunter2'));
        $this->assertSame('legacy:plaintext', $e->label('hunter2'));
    }

    public function testReaderOrderFirstMatchWins(): void
    {
        $key = bin2hex(random_bytes(32));
        $e = new Envelope(KeyRing::single(random_bytes(32)), array_merge(LegacyReaders::settings($key), LegacyReaders::plaintext()));
        $this->assertSame('ok', $e->open(Editions::encryptSetting('ok', $key), 'c'));
        $this->assertSame('plain', $e->open('plain', 'c'));
    }
}
