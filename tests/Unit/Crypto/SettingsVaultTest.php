<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RivetCore\Crypto\DecryptionFailed;
use RivetCore\Crypto\KeyGenerator;
use RivetCore\Crypto\KeyPurpose;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\KeyUnavailable;
use RivetCore\Crypto\LegacyReaders;
use RivetCore\Crypto\SettingsVault;
use RivetCore\Crypto\UnknownKeyId;
use RivetCore\Tests\Fixtures\LegacyEditions\LegacyEditionFunctions as Editions;

require_once __DIR__ . '/../../Fixtures/crypto/LegacyEditionFunctions.php';

final class SettingsVaultTest extends TestCase
{
    public function testRoundTripAndBinding(): void
    {
        $v = new SettingsVault(KeyRing::single(random_bytes(32)));
        $c = $v->encrypt('smtp_password', 'p@ss');
        $this->assertStringStartsWith('v3:k1:', $c);
        $this->assertSame('p@ss', $v->decrypt('smtp_password', $c));
        $this->assertSame('', $v->encrypt('x', ''));
        $this->assertSame('', $v->decrypt('x', ''));
        $this->expectException(DecryptionFailed::class);
        $v->decrypt('imap_password', $c); // copied to another setting
    }

    public function testPurposesAreSeparated(): void
    {
        $ring = KeyRing::single(random_bytes(32));
        $settings = new SettingsVault($ring);
        $totp = new SettingsVault($ring, [], KeyPurpose::TOTP);
        $c = $totp->encrypt('user:7', 'JBSWY3DPEHPK3PXP');
        $this->assertSame('JBSWY3DPEHPK3PXP', $totp->decrypt('user:7', $c));
        $this->expectException(DecryptionFailed::class);
        $settings->decrypt('user:7', $c);
    }

    public function testFailsClosedWithoutAKey(): void
    {
        $v = new SettingsVault(KeyRing::empty());
        try {
            $v->encrypt('a', 'secret');
            $this->fail('stored a secret without a key');
        } catch (KeyUnavailable) {
            $this->addToAssertionCount(1);
        }
        $c = (new SettingsVault(KeyRing::single(random_bytes(32))))->encrypt('a', 'secret');
        $this->expectException(KeyUnavailable::class);
        $v->decrypt('a', $c); // not ''
    }

    public function testLazyRewrapOfLegacyHandsTheReplacementToTheCallback(): void
    {
        $legacy = bin2hex(random_bytes(32));
        $stored = Editions::encryptSetting('smtp-secret', $legacy);
        $v = new SettingsVault(KeyGenerator::ringFromLegacySettingsKey($legacy), LegacyReaders::settings($legacy));
        $calls = [];
        $this->assertSame('smtp-secret', $v->decrypt('smtp', $stored, function (string $new, string $old) use (&$calls): void {
            $calls[] = [$new, $old];
        }));
        $this->assertCount(1, $calls);
        $this->assertSame($stored, $calls[0][1]);
        $this->assertStringStartsWith('v3:k1:', $calls[0][0]);
        // The persisted value is current: reading it again triggers nothing.
        $again = [];
        $this->assertSame('smtp-secret', $v->decrypt('smtp', $calls[0][0], function () use (&$again): void {
            $again[] = 1;
        }));
        $this->assertSame([], $again);
    }

    public function testRetiredKidIsRewrappedAfterRotation(): void
    {
        $k1 = KeyRing::single(random_bytes(32), 'k1');
        $old = (new SettingsVault($k1))->encrypt('n', 'v');
        $v = new SettingsVault($k1->withKey(random_bytes(32), 'k2', null, true));
        $read = $v->read('n', $old);
        $this->assertSame('v', $read->plaintext);
        $this->assertSame('v3:k1', $read->format);
        $this->assertNotNull($read->rewrapped);
        $this->assertStringStartsWith('v3:k2:', (string) $read->rewrapped);
        $this->assertNull($v->read('n', (string) $read->rewrapped)->rewrapped);
    }

    public function testPersistFailureIsLoggedAndNeverFailsTheRead(): void
    {
        $legacy = bin2hex(random_bytes(32));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $message . json_encode($context);
            }
        };
        $v = new SettingsVault(KeyGenerator::ringFromLegacySettingsKey($legacy), LegacyReaders::settings($legacy), KeyPurpose::SETTINGS, $logger);
        $out = $v->decrypt('smtp', Editions::encryptSetting('s', $legacy), function (): void {
            throw new \RuntimeException('db down with secret s');
        });
        $this->assertSame('s', $out);
        $this->assertCount(1, $logger->lines);
        $this->assertStringNotContainsString('db down', $logger->lines[0]);
    }

    public function testReadOnlyRingStillReadsWithoutARewrap(): void
    {
        $key = random_bytes(32);
        $c = (new SettingsVault(KeyRing::single($key)))->encrypt('n', 'v');
        $v = new SettingsVault(KeyRing::fromKeys(['k1' => $key], null));
        $this->assertSame('v', $v->read('n', $c)->plaintext);
        $this->assertNull($v->read('n', $c)->rewrapped);
    }

    public function testSubkeysAreDerivedPerPurposeFromTheMasterKek(): void
    {
        $ring = KeyRing::single(random_bytes(32), 'k1')->withKey(random_bytes(32), 'k2', null, true);
        $v = new SettingsVault($ring);
        $m = $v->subkey(KeyPurpose::MEDIA_TOKEN);
        $this->assertSame(32, strlen($m));
        $this->assertNotSame($m, $v->subkey(KeyPurpose::TRAINING_PIN));
        $this->assertNotSame($m, $v->subkey(KeyPurpose::MEDIA_TOKEN, 32, 'k1'), 'an older kid derives a different key (verify old tokens with it)');
        $this->assertSame($m, KeyPurpose::derive($ring->key('k2'), KeyPurpose::MEDIA_TOKEN));
        $this->assertSame(64, strlen($v->subkey(KeyPurpose::MEDIA_TOKEN, 64)));
        $this->expectException(UnknownKeyId::class);
        $v->subkey('x', 32, 'zz');
    }

    public function testEmptyNameIsRefusedAndUnreadableLegacyThrows(): void
    {
        $v = new SettingsVault(KeyRing::single(random_bytes(32)));
        try {
            $v->encrypt('', 'x');
            $this->fail();
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(DecryptionFailed::class);
        $v->decrypt('n', 'ENC2:whatever');
    }
}
