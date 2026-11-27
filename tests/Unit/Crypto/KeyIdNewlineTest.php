<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\InvalidKeyMaterial;
use RivetCore\Crypto\KeyFile;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\KeyRingLoader;

/**
 * CORE-DATA-3 (red team 2026-11-21, core-data/poc_core_data_3_keyring_kid_newline.php): KeyRing::KID_PATTERN ended in `$` without D, so "k1\n" was a valid key id
 * beside "k1" and was written into the header of stored envelopes ("v3:k1\n:..."). A key id now ends where the value ends.
 */
final class KeyIdNewlineTest extends TestCase
{
    public function testTheProofOfConceptRingIsRefused(): void
    {
        $this->expectException(InvalidKeyMaterial::class);
        KeyRing::fromKeys(['k1' => random_bytes(32), "k1\n" => random_bytes(32)], "k1\n");
    }

    public function testAKeyIdWithATrailingNewlineIsRefusedEverywhereARingIsBuilt(): void
    {
        foreach ([
            'single' => static fn () => KeyRing::single(random_bytes(32), "k1\n"),
            'fromKeys, not active' => static fn () => KeyRing::fromKeys(['k1' => random_bytes(32), "k2\n" => random_bytes(32)], 'k1'),
            'key file JSON' => static fn () => KeyFile::ringFromJson((string) json_encode(['active' => "k1\n", 'keys' => ["k1\n" => bin2hex(random_bytes(32))]])),
            'environment key id' => static fn () => (new KeyRingLoader(env: static fn (string $n): ?string => ['RIVETCORE_KEK' => bin2hex(random_bytes(32)), 'RIVETCORE_KEK_ID' => "k1\n"][$n] ?? null))->load(null),
        ] as $where => $build) {
            try {
                $build();
                $this->fail("$where accepted a key id with a trailing newline");
            } catch (InvalidKeyMaterial) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnEnvelopeHeaderWithANewlineKidIsNotAKeyId(): void
    {
        $this->assertNull(Envelope::kidOf("v3:k1\n:AAAA"));
        $this->assertSame('k1', Envelope::kidOf('v3:k1:AAAA'));
        $ring = KeyRing::single(random_bytes(32), 'k1');
        $this->assertSame('k1', Envelope::kidOf((new Envelope($ring))->seal('x', 'ctx')));
    }

    public function testTheKeyFileStillAcceptsAKeyValueWithATrailingNewline(): void
    {
        // the loader trims a key VALUE (a hex key pasted with its line end keeps working); only the key ID is strict
        $hex = bin2hex(random_bytes(32));
        $this->assertSame(hex2bin($hex), KeyFile::decodeKey($hex . "\n"));
        $ring = KeyFile::ringFromJson((string) json_encode(['active' => 'k1', 'keys' => ['k1' => $hex . "\n"]]));
        $this->assertSame(['k1'], $ring->kids());
    }
}
