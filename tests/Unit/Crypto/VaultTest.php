<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use RivetCore\Crypto\DecryptionFailed;
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\InvalidKeyMaterial;
use RivetCore\Crypto\KdfParams;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\Rewrapper;
use RivetCore\Crypto\RewrapOptions;
use RivetCore\Crypto\VaultCipher;
use RivetCore\Crypto\VaultFieldReencryptor;
use RivetCore\Crypto\VaultKeyWrap;
use RivetCore\Testing\InMemoryRewrapSource;
use RivetCore\Testing\InMemoryRewrapStateStore;
use RivetCore\Tests\Fixtures\LegacyEditions\LegacyEditionFunctions as Editions;

require_once __DIR__ . '/../../Fixtures/crypto/LegacyEditionFunctions.php';

final class VaultTest extends TestCase
{
    private function fast(): VaultKeyWrap
    {
        // The smallest parameters the library allows; production uses KdfParams::preferred().
        return new VaultKeyWrap(KdfParams::argon2id(KdfParams::MIN_ARGON_MEMORY_KIB, KdfParams::MIN_ARGON_OPS));
    }

    public function testKdfKnownAnswersMatchAnIndependentImplementation(): void
    {
        $pw = 'correct horse battery staple';
        $salt = implode('', array_map('chr', range(0, 15)));
        $this->assertSame('818259b6310026a8e0dbac5d2e6927abcfdb07b32258fac4f61b18b80f929085', bin2hex(KdfParams::argon2id(19456, 2)->derive($pw, $salt)));
        $this->assertSame('ef177144eec9420cbc1093d2a8b344a92bc506d0d4ec9c028dd19f8324d8c1e6', bin2hex(KdfParams::pbkdf2(600000)->derive($pw, $salt)));
    }

    public function testWrapUnwrapAndHeaderCarriesTheParameters(): void
    {
        $dek = VaultKeyWrap::generateDek();
        $w = $this->fast()->wrap($dek, 'pw one', 'user:7');
        $this->assertStringStartsWith('vw3:argon2id:m=19456,t=2:', $w);
        $this->assertTrue(VaultKeyWrap::isWrap($w));
        $this->assertSame($dek, $this->fast()->unwrap($w, 'pw one', 'user:7'));
        // Any wrapper instance can unwrap: the parameters come from the wrap itself.
        $this->assertSame($dek, (new VaultKeyWrap(KdfParams::pbkdf2()))->unwrap($w, 'pw one', 'user:7'));
        $this->assertNotSame($w, $this->fast()->wrap($dek, 'pw one', 'user:7'), 'fresh salt and nonce every time');
    }

    public function testWrongPasswordWrongContextAndTamperingAreAllRefused(): void
    {
        $dek = VaultKeyWrap::generateDek();
        $vw = $this->fast();
        $w = $vw->wrap($dek, 'pw', 'user:7');
        $parts = explode(':', $w);
        $tampered = [];
        $weaker = $parts;
        $weaker[2] = 'm=19456,t=3';
        $tampered['params'] = implode(':', $weaker);
        $salt = $parts;
        $salt[3] = base64_encode(random_bytes(16));
        $tampered['salt'] = implode(':', $salt);
        $raw = (string) base64_decode($parts[4]);
        $raw[20] = chr(ord($raw[20]) ^ 1);
        $ct = $parts;
        $ct[4] = base64_encode($raw);
        $tampered['ciphertext'] = implode(':', $ct);
        $cases = [['wrong password', $w, 'nope', 'user:7'], ['wrong user', $w, 'pw', 'user:8']];
        foreach ($tampered as $name => $t) {
            $cases[] = [$name, $t, 'pw', 'user:7'];
        }
        foreach ($cases as [$name, $wrap, $pw, $ctx]) {
            try {
                $vw->unwrap($wrap, $pw, $ctx);
                $this->fail("opened: $name");
            } catch (DecryptionFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMalformedWrapsAreRefusedWithoutDerivingAnything(): void
    {
        $vw = $this->fast();
        foreach (['', 'x', 'vw3:', 'vw3:argon2id:m=1,t=1:AAAA:AAAA', 'vw3:argon2id:m=99999999,t=3:' . base64_encode(random_bytes(16)) . ':AAAA', 'vw3:pbkdf2-sha256:i=1000:' . base64_encode(random_bytes(16)) . ':AAAA', 'vw3:pbkdf2-sha256:i=999999999999:' . base64_encode(random_bytes(16)) . ':AAAA', 'vw3:scrypt:n=1:AAAA:AAAA'] as $bad) {
            try {
                $vw->unwrap($bad, 'pw', 'c');
                $this->fail('opened ' . $bad);
            } catch (DecryptionFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testWeakParametersCannotBeCreated(): void
    {
        foreach ([fn () => KdfParams::pbkdf2(100000), fn () => KdfParams::argon2id(1024, 3), fn () => KdfParams::argon2id(65536, 1), fn () => KdfParams::pbkdf2(KdfParams::MAX_PBKDF2_ITERATIONS + 1)] as $make) {
            try {
                $make();
                $this->fail();
            } catch (InvalidKeyMaterial) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPasswordChangeKeepsTheDekAndRotatesSaltAndRefusesTheOldPassword(): void
    {
        $dek = VaultKeyWrap::generateDek();
        $vw = $this->fast();
        $w1 = $vw->wrap($dek, 'old', 'user:7');
        $w2 = $vw->rewrap($w1, 'old', 'new', 'user:7');
        $this->assertSame($dek, $vw->unwrap($w2, 'new', 'user:7'));
        $this->assertNotSame(explode(':', $w1)[3], explode(':', $w2)[3]);
        $this->expectException(DecryptionFailed::class);
        $vw->unwrap($w2, 'old', 'user:7');
    }

    public function testPbkdf2WrapAndUpgradeDetection(): void
    {
        $dek = VaultKeyWrap::generateDek();
        $p = new VaultKeyWrap(KdfParams::pbkdf2());
        $w = $p->wrap($dek, 'pw', 'user:1');
        $this->assertStringStartsWith('vw3:pbkdf2-sha256:i=600000:', $w);
        $this->assertSame($dek, $p->unwrap($w, 'pw', 'user:1'));
        $this->assertTrue($this->fast()->needsUpgrade($w), 'PBKDF2 is weaker than the Argon2id this instance writes');
        $this->assertFalse($p->needsUpgrade($w));
        $this->assertFalse($this->fast()->needsUpgrade($this->fast()->wrap($dek, 'pw', 'user:1')));
        $this->assertTrue((new VaultKeyWrap(KdfParams::argon2id(65536, 3)))->needsUpgrade($this->fast()->wrap($dek, 'pw', 'u')));
        $this->assertFalse($p->needsUpgrade('garbage'));
    }

    public function testRejectsBadInputs(): void
    {
        foreach ([fn () => $this->fast()->wrap(random_bytes(16), 'pw', 'c'), fn () => $this->fast()->wrap(random_bytes(32), '', 'c'), fn () => $this->fast()->wrap(random_bytes(32), 'pw', '')] as $call) {
            try {
                $call();
                $this->fail();
            } catch (\InvalidArgumentException|InvalidKeyMaterial) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInstanceWrapRecoversTheDekWhenAUserWrapIsStale(): void
    {
        $kek = new Envelope(KeyRing::single(random_bytes(32)));
        $dek = VaultKeyWrap::generateDek();
        $vw = $this->fast();
        $w = $vw->wrapForInstance($dek, $kek, 'vault');
        $this->assertStringStartsWith('v3:k1:', $w);
        $this->assertSame($dek, $vw->unwrapForInstance($w, $kek, 'vault'));
        $this->expectException(DecryptionFailed::class);
        $vw->unwrapForInstance($w, $kek, 'other');
    }

    public function testCipherBindsCiphertextToItsCredentialAndField(): void
    {
        $c = new VaultCipher(VaultKeyWrap::generateDek());
        $s = $c->seal('hunter2', 42, 'password');
        $this->assertSame('hunter2', $c->open($s, 42, 'password'));
        $this->assertSame('hunter2', $c->open($s, '42', 'password'));
        foreach ([[43, 'password'], [42, 'username'], [42, '']] as [$id, $field]) {
            try {
                $c->open($s, $id, $field);
                $this->fail("moved to $id/$field");
            } catch (DecryptionFailed) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('', $c->seal('', 1));
        $this->assertSame('', $c->open('', 1));
        $this->assertSame('credential:42:password', VaultCipher::contextFor(42, 'password'));
        $this->assertSame('credential:42', VaultCipher::contextFor('42'));
    }

    public function testCipherWithAnotherDekCannotOpen(): void
    {
        $s = (new VaultCipher(VaultKeyWrap::generateDek()))->seal('x', 1);
        $this->expectException(DecryptionFailed::class);
        (new VaultCipher(VaultKeyWrap::generateDek()))->open($s, 1);
    }

    public function testVaultRewrapMigratesLegacyFieldsInResumableBatches(): void
    {
        $master = Editions::randomString();
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows[$i] = ['ciphertext' => Editions::encryptCredentialEntryWithKey("pw-$i", $master), 'context' => VaultCipher::contextFor($i, 'password')];
        }
        $rows[13] = ['ciphertext' => '', 'context' => VaultCipher::contextFor(13, 'password')];
        $src = new InMemoryRewrapSource('vault.credentials.password', $rows);
        $cipher = new VaultCipher(VaultKeyWrap::generateDek());
        $re = new VaultFieldReencryptor($cipher, legacyDek: $master);
        $r = new Rewrapper(new InMemoryRewrapStateStore());
        $first = $r->run($src, $re, new RewrapOptions(batchSize: 5, maxBatches: 1));
        $this->assertFalse($first->completed);
        $done = $r->run($src, $re, new RewrapOptions(batchSize: 5));
        $this->assertTrue($done->isClean());
        for ($i = 1; $i <= 12; $i++) {
            $this->assertSame("pw-$i", $cipher->open((string) $src->get((string) $i), $i, 'password'));
        }
        $this->assertSame(0, $r->run($src, $re)->rewrapped);
    }

    public function testDekRotationRewrapsToTheNewDekAndIsIdempotent(): void
    {
        $old = new VaultCipher(VaultKeyWrap::generateDek(), 'dek1');
        $new = new VaultCipher(VaultKeyWrap::generateDek(), 'dek2');
        $rows = [];
        foreach ([1, 2, 3] as $i) {
            $rows[$i] = ['ciphertext' => $old->seal("s$i", $i, 'password'), 'context' => VaultCipher::contextFor($i, 'password')];
        }
        $src = new InMemoryRewrapSource('v', $rows);
        $re = new VaultFieldReencryptor($new, from: $old);
        $rep = (new Rewrapper(new InMemoryRewrapStateStore()))->run($src, $re);
        $this->assertSame(3, $rep->rewrapped);
        $this->assertSame('s2', $new->open((string) $src->get('2'), 2, 'password'));
        $this->assertSame(0, (new Rewrapper(new InMemoryRewrapStateStore()))->run($src, $re)->rewrapped);
    }

    public function testReencryptorWithoutALegacyKeyFailsInsteadOfGuessing(): void
    {
        $re = new VaultFieldReencryptor(new VaultCipher(VaultKeyWrap::generateDek()));
        $this->expectException(DecryptionFailed::class);
        $re->reencrypt(Editions::encryptCredentialEntryWithKey('x', 'abcdefghijklmnop'), 'credential:1');
    }
}
