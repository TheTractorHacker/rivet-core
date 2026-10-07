<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\SecretBoxInterface;

/**
 * Conformance kit for {@see SecretBoxInterface}.
 *
 * Checks: decrypt(encrypt(x)) === x for short, unicode, binary and long values; ciphertext is a non-empty string that does not
 * contain the plaintext; encrypting twice both decrypt; decrypt() returns '' for '', for text that is not a ciphertext and for a
 * truncated ciphertext, and never throws.
 *
 * @api
 */
abstract class SecretBoxConformanceTestCase extends TestCase
{
    abstract protected function box(): SecretBoxInterface;

    /** @return array<string,array{string}> */
    public static function plaintexts(): array
    {
        return [
            'short' => ['hunter2'],
            'key-like' => [str_repeat('ab12', 16)],
            'unicode' => ["pässwörd \u{1F511} 日本語"],
            'binary' => [random_bytes(64)],
            'long' => [str_repeat('x', 10000)],
            'quotes' => ["'\"\\ ; -- \n\t"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plaintexts')]
    public function testRoundTrip(string $plain): void
    {
        $c = $this->box()->encrypt($plain);
        $this->assertNotSame('', $c);
        $this->assertSame($plain, $this->box()->decrypt($c));
    }

    public function testCiphertextDoesNotContainThePlaintext(): void
    {
        $plain = 'visible-secret-' . bin2hex(random_bytes(6));
        $this->assertStringNotContainsString($plain, $this->box()->encrypt($plain));
    }

    public function testEachEncryptionDecrypts(): void
    {
        $a = $this->box()->encrypt('same');
        $b = $this->box()->encrypt('same');
        $this->assertSame('same', $this->box()->decrypt($a));
        $this->assertSame('same', $this->box()->decrypt($b));
    }

    public function testDecryptOfGarbageIsTheEmptyStringAndNeverThrows(): void
    {
        foreach (['', 'not a ciphertext', "\0\0\0", str_repeat('A', 5000), 'AAAA'] as $junk) {
            $this->assertSame('', $this->box()->decrypt($junk), 'input: ' . substr(bin2hex($junk), 0, 20));
        }
    }

    public function testTruncatedCiphertextDecryptsToTheEmptyString(): void
    {
        $c = $this->box()->encrypt('a secret long enough to be cut in half');
        $this->assertSame('', $this->box()->decrypt(substr($c, 0, intdiv(strlen($c), 2))));
    }
}
