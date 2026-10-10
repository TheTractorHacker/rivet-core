<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Crypto\CryptoException;
use RivetCore\Crypto\DecryptionFailed;
use RivetCore\Crypto\EnvelopeInterface;
use RivetCore\Crypto\KeyGenerator;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\KeyUnavailable;
use RivetCore\Crypto\LegacyReader;
use RivetCore\Crypto\LegacyReaders;
use RivetCore\Crypto\UnknownKeyId;

/**
 * Conformance kit for {@see EnvelopeInterface}.
 *
 * Checks: round trip (empty, short, unicode, binary, 1 MB); `v3:<kid>:` prefix naming the active kid; no plaintext in the output; a fresh
 * nonce per call (also across two instances with the same key); wrong key, wrong context and an empty context are refused; every
 * single-character change and every truncation of a ciphertext is refused with a CryptoException (never a wrong plaintext, never
 * ''); an unknown kid is UnknownKeyId; an empty ring is KeyUnavailable for sealing and for opening v3 values; old keys keep
 * opening after a rotation, needsRewrap()/rewrap() move them to the active key and the result opens to the same plaintext; rewrap
 * of an unreadable value fails and leaves nothing behind; legacy readers are honoured (ENC2 sample sealed with the editions' scheme);
 * labels name the key or the legacy reader; garbage is DecryptionFailed.
 *
 * @api
 */
abstract class EnvelopeConformanceTestCase extends TestCase
{
    /**
     * Build the edition's envelope over the given ring and legacy readers (normally `new Envelope($ring, $legacyReaders)`; wrappers
     * and decorators are what this kit is for).
     *
     * @param list<LegacyReader> $legacyReaders
     */
    abstract protected function make(KeyRing $ring, array $legacyReaders = []): EnvelopeInterface;

    /** @param class-string<\Throwable> $class */
    private function assertRefused(string $class, callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);

            return;
        }
        $this->fail('expected ' . $class . ' but the call succeeded');
    }

    private function ring(string $kid = 'k1'): KeyRing
    {
        return KeyRing::single(KeyGenerator::key(), $kid);
    }

    /** @return array<string,array{string}> */
    public static function plaintexts(): array
    {
        return [
            'empty' => [''],
            'short' => ['hunter2'],
            'zero' => ['0'],
            'unicode' => ["pässwörd \u{1F511} 日本語"],
            'binary' => [random_bytes(300)],
            'nul' => ["a\0b\0"],
            'one megabyte' => [str_repeat('0123456789abcdef', 65536)],
        ];
    }

    #[DataProvider('plaintexts')]
    public function testRoundTrip(string $plain): void
    {
        $e = $this->make($this->ring());
        $this->assertSame($plain, $e->open($e->seal($plain, 'rec:1'), 'rec:1'));
    }

    public function testFormatNamesTheActiveKidAndHidesThePlaintext(): void
    {
        $e = $this->make($this->ring('kid-7'));
        $plain = 'visible-secret-' . bin2hex(random_bytes(6));
        $c = $e->seal($plain, 'rec:1');
        $this->assertStringStartsWith('v3:kid-7:', $c);
        $this->assertStringNotContainsString($plain, $c);
        $this->assertSame('v3:kid-7', $e->label($c));
        $this->assertSame(1, preg_match('/^v3:kid-7:[A-Za-z0-9+\/]+={0,2}$/', $c));
    }

    public function testEverySealUsesAFreshNonce(): void
    {
        $ring = $this->ring();
        $seen = [];
        foreach ([$this->make($ring), $this->make($ring)] as $e) {
            for ($i = 0; $i < 100; $i++) {
                $seen[] = substr($e->seal('same', 'rec:1'), 6, 16); // the base64 of the first 12 bytes (nonce) is 16 characters
            }
        }
        $this->assertCount(200, array_unique($seen), 'a nonce repeated under one key breaks GCM');
        $c1 = $this->make($ring)->seal('same', 'rec:1');
        $c2 = $this->make($ring)->seal('same', 'rec:1');
        $this->assertNotSame($c1, $c2);
    }

    public function testWrongKeyIsRefused(): void
    {
        $c = $this->make($this->ring())->seal('secret', 'rec:1');
        $this->assertRefused(DecryptionFailed::class, fn () => $this->make($this->ring())->open($c, 'rec:1'));
    }

    public function testWrongContextIsRefused(): void
    {
        $e = $this->make($this->ring());
        $c = $e->seal('secret', 'credential:1:password');
        foreach (['credential:2:password', 'credential:1:username', 'credential:1:passwor', 'CREDENTIAL:1:password', 'credential:1:password '] as $ctx) {
            try {
                $e->open($c, $ctx);
                $this->fail("opened under context '$ctx'");
            } catch (DecryptionFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEmptyContextIsRejectedBothWays(): void
    {
        $e = $this->make($this->ring());
        $c = $e->seal('x', 'rec:1');
        foreach ([fn () => $e->seal('x', ''), fn () => $e->open($c, '')] as $call) {
            try {
                $call();
                $this->fail('an empty context was accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEveryCharacterChangeIsRefused(): void
    {
        $e = $this->make($this->ring());
        $c = $e->seal('a secret long enough to cover a few blocks', 'rec:1');
        $refused = 0;
        for ($i = 0; $i < strlen($c); $i++) {
            $t = $c;
            $t[$i] = $c[$i] === 'A' ? 'B' : 'A';
            try {
                $out = $e->open($t, 'rec:1');
                $this->fail("a change at offset $i opened to " . var_export($out === 'a secret long enough to cover a few blocks', true));
            } catch (CryptoException) {
                $refused++;
            }
        }
        $this->assertSame(strlen($c), $refused);
    }

    public function testEveryTruncationAndExtensionIsRefused(): void
    {
        $e = $this->make($this->ring());
        $c = $e->seal('a secret', 'rec:1');
        for ($n = 0; $n < strlen($c); $n++) {
            try {
                $e->open(substr($c, 0, $n), 'rec:1');
                $this->fail("a truncation to $n characters opened");
            } catch (CryptoException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([$c . 'A', $c . '=', $c . "\n", $c . ' ', 'x' . $c] as $t) {
            try {
                $e->open($t, 'rec:1');
                $this->fail('a modified value opened');
            } catch (CryptoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTamperedEmptyPlaintextIsRefusedToo(): void
    {
        $e = $this->make($this->ring());
        $c = $e->seal('', 'rec:1');
        $this->assertSame('', $e->open($c, 'rec:1'));
        $raw = (string) base64_decode(substr($c, 6));
        $raw[14] = chr(ord($raw[14]) ^ 1); // a bit of the tag
        $this->assertRefused(DecryptionFailed::class, fn () => $e->open('v3:k1:' . base64_encode($raw), 'rec:1'));
    }

    public function testUnknownKidIsUnknownKeyId(): void
    {
        $key = KeyGenerator::key();
        $c = $this->make(KeyRing::single($key, 'old'))->seal('x', 'rec:1');
        $other = $this->make($this->ring('new'));
        try {
            $other->open($c, 'rec:1');
            $this->fail('opened with a ring that lacks the kid');
        } catch (UnknownKeyId $e) {
            $this->assertSame('old', $e->kid);
        }
    }

    public function testEmptyRingFailsClosed(): void
    {
        $e = $this->make(KeyRing::empty());
        try {
            $e->seal('secret', 'rec:1');
            $this->fail('sealed without a key');
        } catch (KeyUnavailable) {
            $this->addToAssertionCount(1);
        }
        $c = $this->make($this->ring())->seal('x', 'rec:1');
        $this->assertRefused(KeyUnavailable::class, fn () => $e->open($c, 'rec:1'));
    }

    public function testRingWithoutAnActiveKeyOpensButDoesNotSeal(): void
    {
        $key = KeyGenerator::key();
        $c = $this->make(KeyRing::single($key, 'k1'))->seal('x', 'rec:1');
        $readOnly = $this->make(KeyRing::fromKeys(['k1' => $key], null));
        $this->assertSame('x', $readOnly->open($c, 'rec:1'));
        $this->assertRefused(KeyUnavailable::class, fn () => $readOnly->seal('y', 'rec:1'));
    }

    public function testRotationKeepsOldValuesReadableAndRewrapMovesThem(): void
    {
        $k1 = KeyGenerator::key();
        $old = $this->make(KeyRing::single($k1, 'k1'));
        $c1 = $old->seal('secret', 'rec:1');
        $ring2 = KeyRing::single($k1, 'k1')->withKey(KeyGenerator::key(), 'k2', null, true);
        $e = $this->make($ring2);
        $this->assertSame('secret', $e->open($c1, 'rec:1'));
        $this->assertTrue($e->needsRewrap($c1));
        $c2 = $e->rewrap($c1, 'rec:1');
        $this->assertStringStartsWith('v3:k2:', $c2);
        $this->assertSame('secret', $e->open($c2, 'rec:1'));
        $this->assertFalse($e->needsRewrap($c2));
        $this->assertSame('v3:k1', $e->label($c1));
        $this->assertSame('v3:k2', $e->label($c2));
        // The new value no longer needs k1.
        $this->assertSame('secret', $this->make(KeyRing::fromKeys(['k2' => $ring2->key('k2')], 'k2'))->open($c2, 'rec:1'));
    }

    public function testRewrapOfAnUnreadableValueFails(): void
    {
        $e = $this->make($this->ring());
        $c = $e->seal('secret', 'rec:1');
        $this->assertRefused(CryptoException::class, fn () => $e->rewrap($c, 'rec:2'));
    }

    public function testEmptyAndMissingValuesNeverNeedARewrap(): void
    {
        $e = $this->make($this->ring());
        $this->assertFalse($e->needsRewrap(''));
        $this->assertSame('empty', $e->label(''));
    }

    public function testLegacyEnc2ValuesAreReadAndMarkedForRewrap(): void
    {
        $legacyKey = bin2hex(random_bytes(32));
        $nonce = random_bytes(12);
        $tag = '';
        $ct = (string) openssl_encrypt('smtp-secret', 'aes-256-gcm', hash('sha256', $legacyKey, true), OPENSSL_RAW_DATA, $nonce, $tag);
        $stored = 'ENC2:' . base64_encode($nonce . $tag . $ct);
        $e = $this->make($this->ring(), LegacyReaders::settings($legacyKey));
        $this->assertSame('smtp-secret', $e->open($stored, 'settings|smtp'));
        $this->assertTrue($e->needsRewrap($stored));
        $this->assertSame('legacy:ENC2', $e->label($stored));
        $new = $e->rewrap($stored, 'settings|smtp');
        $this->assertStringStartsWith('v3:k1:', $new);
        $this->assertSame('smtp-secret', $e->open($new, 'settings|smtp'));
    }

    public function testLegacyValuesAreRefusedWithoutAReader(): void
    {
        $e = $this->make($this->ring());
        $this->assertSame('unknown', $e->label('ENC2:AAAA'));
        $this->assertRefused(DecryptionFailed::class, fn () => $e->open('ENC2:AAAA', 'rec:1'));
    }

    public function testGarbageIsDecryptionFailedNeverAValue(): void
    {
        $e = $this->make($this->ring());
        foreach (['x', 'not a ciphertext', "\0\0\0", str_repeat('A', 5000), 'v3:', 'v3:k1', 'v3:k1:', 'v3:k1:AAAA', 'v3::AAAA', 'v3:k1:!!!!', 'v3:k 1:AAAA', 'v3:k1:' . base64_encode(str_repeat("\0", 27))] as $junk) {
            try {
                $e->open($junk, 'rec:1');
                $this->fail('garbage opened: ' . substr(bin2hex($junk), 0, 20));
            } catch (DecryptionFailed|UnknownKeyId) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNonCanonicalBase64IsRefused(): void
    {
        $e = $this->make($this->ring());
        $c = $e->seal('a', 'rec:1'); // 29 raw bytes: the last base64 character before the '=' carries 2 unused bits
        $this->assertSame('=', substr($c, -1));
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        $variant = substr($c, 0, -2) . $alphabet[strpos($alphabet, $c[strlen($c) - 2]) ^ 1] . '=';
        $this->assertNotSame($c, $variant);
        $this->assertRefused(DecryptionFailed::class, fn () => $e->open($variant, 'rec:1'));
    }
}
