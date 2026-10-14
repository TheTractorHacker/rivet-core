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
        $raw = (string) base64_decode(strtr($c, '@$', '+/'));
        // Flip the last byte (never replace it with a constant: the old 'X' replacement was a no-op one time in 256).
        $tampered = strtr(base64_encode(substr($raw, 0, -1) . chr(ord(substr($raw, -1)) ^ 0x01)), '+/', '@$');
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

    public function testLaunchUrlHideParameter(): void
    {
        $b = 'https://m.example.com';
        $old = $b . '/?login=x&gotonode=n&viewmode=11';
        $this->assertSame($old, M::launchUrl($b, '', 'x', 'n', 11, 0), 'hide=0 is the old URL, byte for byte');
        $this->assertSame($old, M::launchUrl($b, '', 'x', 'n'), 'the default of the method is the old URL');
        foreach ([11, 12, 13] as $view) {
            foreach ([1, 16, 31, 63, 255] as $hide) {
                $this->assertSame($b . '/?login=x&gotonode=n&viewmode=' . $view . '&hide=' . $hide, M::launchUrl($b, '', 'x', 'n', $view, $hide));
            }
            $this->assertSame($b . '/?login=x&gotonode=n&viewmode=' . $view, M::launchUrl($b, '', 'x', 'n', $view, 0));
        }
        foreach ([-1, -31, 256, 1000, PHP_INT_MAX, PHP_INT_MIN] as $bad) {
            $this->assertSame($old, M::launchUrl($b, '', 'x', 'n', 11, $bad), "out of range $bad adds nothing");
        }
        $this->assertSame($b . '/d/?login=x&gotonode=n&viewmode=11&hide=31', M::launchUrl($b, 'd', 'x', 'n', 99, 31), 'an unknown view falls back to the desktop and keeps hide');
        // hostile input stays encoded and cannot add a parameter; hide is an int and cannot carry text
        $u = M::launchUrl($b, 'a/b?x=1#', 'c&hide=0&login=evil', "n&hide=0\r\nHost: x", 11, 31);
        $this->assertSame(1, substr_count($u, '&hide='));
        $this->assertSame(1, substr_count($u, '?'));
        $this->assertStringEndsWith('&viewmode=11&hide=31', $u);
        parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
        $this->assertSame(['login', 'gotonode', 'viewmode', 'hide'], array_keys($q));
        $this->assertSame('c&hide=0&login=evil', $q['login']);
    }
}
