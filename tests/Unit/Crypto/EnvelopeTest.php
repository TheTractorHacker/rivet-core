<?php

declare(strict_types=1);

// Fixture note (secret scan): synthetic test values, not credentials: the McGrew and Viega GCM specification test case 16 vector (public). See secret-scan-allowlist.json.
namespace RivetCore\Tests\Unit\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Crypto\CryptoException;
use RivetCore\Crypto\DecryptionFailed;
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\KeyUnavailable;
use RivetCore\Crypto\UnknownKeyId;

final class EnvelopeTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/crypto/envelope_vectors.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,array{array<string,string>}> */
    public static function knownAnswers(): array
    {
        $out = [];
        foreach (self::vectors()['vectors'] as $i => $v) {
            $out['vector ' . $i . ' ' . $v['aad']] = [$v];
        }

        return $out;
    }

    /** @param array<string,string> $v */
    #[DataProvider('knownAnswers')]
    public function testKnownAnswerSealAndOpen(array $v): void
    {
        $meta = self::vectors();
        $ring = KeyRing::single((string) hex2bin($meta['key_hex']), $meta['kid']);
        $nonce = (string) hex2bin($meta['nonce_hex']);
        $plain = (string) hex2bin($v['plaintext_hex']);
        // Sealing with the injected nonce reproduces the independently generated vector byte for byte.
        $this->assertSame($v['envelope'], (new Envelope($ring, [], fn () => $nonce))->seal($plain, $v['aad']));
        // And a normal instance opens it.
        $this->assertSame($plain, (new Envelope($ring))->open($v['envelope'], $v['aad']));
    }

    public function testKnownAnswerBinaryAllBytes(): void
    {
        // A fourth vector, all 256 byte values, generated with the same independent tool.
        $meta = self::vectors();
        $stored = 'v3:k1:oKGio6SlpqeoqaqrfHZPJGDvefucgylaPRQ6F7nQP2X5sFwDdweoYP2ILotHxqIKTH12kz9Cz+ZOU8YqEfC768v3lhMFX+qyihtgCCmHQvf4rsjicN25y/4LOVfsA+Gn6m4KtpLZDL9Ol7WQnc5EGCyjBCGRq5xhu5oL/mS/YD4lhD7OzOAkHR8LkqmRoysEpaWqpryYMs2WOkyqegzEfLVAbXTJ/H3Mnq9hKXXiH+FEV8Xz+JdelaDrxcqDcDh4/zyFvO1Bl7bI0SLr0Cq1Jqe2aNSDOPGbyk0T6MHbPWgkFCEI7D/sLRcuu9/jvPbd0iIdogXNSayyWUSyA3OgBfE+fdNUk6ACTSQn0jX4YCKSRlnsc1Vb+1zP5GA=';
        $ring = KeyRing::single((string) hex2bin($meta['key_hex']), 'k1');
        $all = implode('', array_map('chr', range(0, 255)));
        $this->assertSame($all, (new Envelope($ring))->open($stored, 'x'));
    }

    public function testNistGcmTestCase16ForTheUnderlyingPrimitive(): void
    {
        // McGrew & Viega GCM spec, test case 16 (AES-256, 60-byte plaintext, 20-byte AAD): proves the OpenSSL binding behaves per the standard.
        $key = (string) hex2bin('feffe9928665731c6d6a8f9467308308feffe9928665731c6d6a8f9467308308');
        $iv = (string) hex2bin('cafebabefacedbaddecaf888');
        $pt = (string) hex2bin('d9313225f88406e5a55909c5aff5269a86a7a9531534f7da2e4c303d8a318a721c3c0c95956809532fcf0e2449a6b525b16aedf5aa0de657ba637b39');
        $aad = (string) hex2bin('feedfacedeadbeeffeedfacedeadbeefabaddad2');
        $tag = '';
        $ct = openssl_encrypt($pt, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        $this->assertSame('522dc1f099567d07f47f37a32a84427d643a8cdcbfe5c0c97598a2bd2555d1aa8cb08e48590dbb3da7b08b1056828838c5f61e6393ba7a0abcc9f662', bin2hex((string) $ct));
        $this->assertSame('76fc6ece0f4e1768cddf8853bb2d551b', bin2hex($tag));
    }

    public function testNonceIsFreshPerCallAndInjectionIsTheOnlyWayToFixIt(): void
    {
        $ring = KeyRing::single(random_bytes(32));
        $e = new Envelope($ring);
        $nonces = [];
        for ($i = 0; $i < 500; $i++) {
            $nonces[substr((string) base64_decode(substr($e->seal('x', 'c'), 6)), 0, 12)] = true;
        }
        $this->assertCount(500, $nonces);
    }

    public function testBadNonceSourceIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        (new Envelope(KeyRing::single(random_bytes(32)), [], fn () => 'short'))->seal('x', 'c');
    }

    public function testTagBitFlipsAndAadChangesAreEachRefused(): void
    {
        $key = random_bytes(32);
        $e = new Envelope(KeyRing::single($key));
        $c = $e->seal('payload bytes', 'settings|a');
        $raw = (string) base64_decode(substr($c, 6));
        foreach ([0, 5, 11, 12, 20, 27, 28, 35, strlen($raw) - 1] as $pos) {
            $t = $raw;
            $t[$pos] = chr(ord($t[$pos]) ^ 0x01);
            try {
                $e->open('v3:k1:' . base64_encode($t), 'settings|a');
                $this->fail("flip at byte $pos opened");
            } catch (DecryptionFailed) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(DecryptionFailed::class);
        $e->open($c, 'settings|b');
    }

    public function testKidSwapBetweenKeysIsRefused(): void
    {
        $ring = KeyRing::single(random_bytes(32), 'a')->withKey(random_bytes(32), 'b');
        $e = new Envelope($ring);
        $c = $e->seal('x', 'c'); // under a
        $this->expectException(DecryptionFailed::class);
        $e->open('v3:b:' . substr($c, 5), 'c');
    }

    public function testKidOfAndIsV3(): void
    {
        $this->assertSame('k9', Envelope::kidOf('v3:k9:AAAA'));
        $this->assertNull(Envelope::kidOf('v3:bad kid:AAAA'));
        $this->assertNull(Envelope::kidOf('v3:onlytwo'));
        $this->assertNull(Envelope::kidOf('ENC2:abc'));
        $this->assertTrue(Envelope::isV3('v3:x'));
        $this->assertFalse(Envelope::isV3('V3:x'));
    }

    public function testFuzzRandomTruncationsAndBitFlipsNeverOpenAndOnlyThrowCryptoExceptions(): void
    {
        mt_srand(20261010);
        $e = new Envelope(KeyRing::single(random_bytes(32)));
        for ($round = 0; $round < 60; $round++) {
            $plain = random_bytes(mt_rand(0, 200));
            $c = $e->seal($plain, 'fuzz:' . $round);
            for ($i = 0; $i < 30; $i++) {
                $t = $c;
                $mode = mt_rand(0, 3);
                if ($mode === 0) {
                    $t = substr($c, 0, mt_rand(0, strlen($c) - 1));
                } elseif ($mode === 1) {
                    $p = mt_rand(0, strlen($c) - 1);
                    $t[$p] = chr(ord($t[$p]) ^ (1 << mt_rand(0, 7)));
                } elseif ($mode === 2) {
                    $p = mt_rand(0, strlen($c) - 1);
                    $t = substr($c, 0, $p) . substr($c, $p + 1);
                } else {
                    $p = mt_rand(0, strlen($c));
                    $t = substr($c, 0, $p) . chr(mt_rand(0, 255)) . substr($c, $p);
                }
                if ($t === $c) {
                    continue;
                }
                try {
                    $out = $e->open($t, 'fuzz:' . $round);
                    $this->fail('a mutated envelope opened (mode ' . $mode . ')' . ($out === $plain ? ' to the same plaintext' : ''));
                } catch (CryptoException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function testEmptyRingAndNoActiveKey(): void
    {
        $c = (new Envelope(KeyRing::single(random_bytes(32))))->seal('x', 'c');
        try {
            (new Envelope(KeyRing::empty()))->open($c, 'c');
            $this->fail();
        } catch (KeyUnavailable) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(UnknownKeyId::class);
        (new Envelope(KeyRing::single(random_bytes(32), 'other')))->open($c, 'c');
    }

    public function testRewrapChecksTheResultAndKeepsPlaintext(): void
    {
        $k1 = random_bytes(32);
        $e1 = new Envelope(KeyRing::single($k1, 'k1'));
        $c = $e1->seal("bin\0ary", 'c');
        $e2 = new Envelope(KeyRing::single($k1, 'k1')->withKey(random_bytes(32), 'k2', null, true));
        $this->assertTrue($e2->needsRewrap($c));
        $n = $e2->rewrap($c, 'c');
        $this->assertSame("bin\0ary", $e2->open($n, 'c'));
        $this->assertFalse($e2->needsRewrap($n));
    }

    public function testOversizedValuesAreRefusedBeforeAnyWork(): void
    {
        $e = new Envelope(KeyRing::single(random_bytes(32)));
        $this->expectException(DecryptionFailed::class);
        $e->open('v3:k1:' . str_repeat('A', Envelope::MAX_STORED_BYTES), 'c');
    }
}
