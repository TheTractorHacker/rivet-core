<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Mesh\MeshCookie as M;

final class MeshCookieTest extends TestCase
{
    private const KEY = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f' . 'ffeeddccbbaa99887766';

    public function testNewLoginKeyIsHex160(): void
    {
        $k = M::newLoginKey();
        $this->assertSame(160, strlen($k));
        $this->assertTrue(ctype_xdigit($k));
        $this->assertNotSame($k, M::newLoginKey());
    }

    public function testEncodeFormatAndRoundTrip(): void
    {
        $iv = str_repeat("\x07", 12);
        $c = M::encode(['u' => 'user//svc', 'a' => 3, 'time' => 1000], self::KEY, $iv);
        $this->assertNotNull($c);
        $this->assertSame($c, M::encode(['u' => 'user//svc', 'a' => 3, 'time' => 1000], self::KEY, $iv), 'deterministic with a fixed IV');
        $this->assertSame(['u' => 'user//svc', 'a' => 3, 'time' => 1000], M::decode($c, self::KEY));
        $this->assertDoesNotMatchRegularExpression('#[+/]#', $c);
        $raw = base64_decode(strtr($c, '@$', '+/'), true);
        $this->assertNotFalse($raw);
        $this->assertSame($iv, substr($raw, 0, 12), 'IV first');
        // Independent decryption: IV(12) || tag(16) || ciphertext, AES-256-GCM, first 32 key bytes.
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', (string) hex2bin(substr(self::KEY, 0, 64)), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        $this->assertSame('{"u":"user\/\/svc","a":3,"time":1000}', $pt);
    }

    public function testRandomIvDiffers(): void
    {
        $a = M::encode(['x' => 1], self::KEY);
        $b = M::encode(['x' => 1], self::KEY);
        $this->assertNotSame($a, $b);
    }

    public function testBadKeysAndCookies(): void
    {
        $this->assertNull(M::encode(['x' => 1], ''));
        $this->assertNull(M::encode(['x' => 1], 'zz'));
        $this->assertNull(M::encode(['x' => 1], str_repeat('ab', 31)), 'a key under 32 bytes is unusable');
        $this->assertNull(M::encode(['x' => 1], 'abc'), 'odd length hex');
        $c = (string) M::encode(['x' => 1], self::KEY);
        $this->assertNull(M::decode($c, str_repeat('11', 40)), 'another key cannot decrypt');
        $this->assertNull(M::decode('short', self::KEY));
        $this->assertNull(M::decode('!!!!', self::KEY));
        $this->assertNull(M::decode($c, 'zz'));
        $tampered = strtr(base64_encode(substr((string) base64_decode(strtr($c, '@$', '+/')), 0, -1) . 'X'), '+/', '@$');
        $this->assertNull(M::decode($tampered, self::KEY), 'GCM tag catches tampering');
    }

    public function testLoginPayload(): void
    {
        $c = M::login(self::KEY, 'dom', 'rivetit-support', 10000);
        $this->assertNotNull($c);
        $this->assertSame(['u' => 'user/dom/rivetit-support', 'a' => 3, 'time' => 9880], M::decode($c, self::KEY));
        $c = M::login(self::KEY, '', 'rivetit-support', 10000);
        $this->assertSame('user//rivetit-support', (M::decode((string) $c, self::KEY) ?? [])['u'] ?? null);
        $this->assertNull(M::login(self::KEY, 'd', '', 1));
        $this->assertNull(M::login('', 'd', 'a', 1));
    }

    public function testAccountName(): void
    {
        $this->assertSame('rivetit-support', M::accountName('rivetit-support', 5, 'bob'));
        $this->assertSame('svc-5-bob.smith', M::accountName('svc-{user_id}-{username}', 5, 'bob.smith'));
        $this->assertSame('svc-5-bobevil', M::accountName('svc-{user_id}-{username}', 5, "bob/ evil\n"));
        $this->assertSame('ab', M::accountName('a b/', 1, 'x'));
        $this->assertSame('', M::accountName('///', 1, 'x'));
    }

    public function testLaunchUrl(): void
    {
        $this->assertSame('https://m.example.com/?login=a%40b%24c&gotonode=node%2F%2FABC&viewmode=11', M::launchUrl('https://m.example.com', '', 'a@b$c', 'node//ABC'));
        $this->assertSame('https://m.example.com/my%20dom/?login=x&gotonode=n&viewmode=11', M::launchUrl('https://m.example.com', 'my dom', 'x', 'n'));
    }
}
