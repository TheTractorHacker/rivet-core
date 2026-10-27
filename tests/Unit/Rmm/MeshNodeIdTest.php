<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Mesh\MeshNodeId as N;

final class MeshNodeIdTest extends TestCase
{
    /** @return array{0:string,1:string,2:string} raw bytes, hex, URL form */
    private static function fresh(bool $needSpecials = false): array
    {
        do {
            $raw = random_bytes(48);
            $url = strtr(rtrim(base64_encode($raw), '='), '+/', '@$');
        } while ($needSpecials && (!str_contains($url, '@') || !str_contains($url, '$')));

        return [$raw, bin2hex($raw), $url];
    }

    public function testRoundTripOnFreshRandomIdsIncludingTheOnesWithAtAndDollar(): void
    {
        for ($i = 0; $i < 200; ++$i) {
            [$raw, $hex, $url] = self::fresh($i % 2 === 0);
            $this->assertSame(64, strlen($url));
            $canon = 'node//' . $url;
            foreach ([$canon, $url, 'node//' . $hex, $hex, strtoupper($hex), 'node//' . strtoupper($hex)] as $in) {
                $this->assertSame($canon, N::normalize($in), $in);
                $this->assertSame($canon, N::canonical($in));
                $this->assertTrue(N::valid($in));
            }
            $this->assertSame($url, N::toUrlForm($canon));
            $this->assertSame($hex, N::toHex($canon));
            $this->assertSame($hex, N::toHex($url));
            $this->assertSame('', N::domain($canon));
            $this->assertSame($raw, base64_decode(strtr($url, '@$', '+/'), true));
        }
    }

    public function testSpecialCharactersAreKeptLiterally(): void
    {
        [, , $url] = self::fresh(true);
        $this->assertStringContainsString('@', (string) N::toUrlForm('node//' . $url));
        $this->assertStringContainsString('$', (string) N::toUrlForm('node//' . $url));
        $this->assertStringNotContainsString('%', (string) N::toUrlForm('node//' . $url));
    }

    public function testAPastedMeshCentralAddressYieldsItsGotonode(): void
    {
        [, $hex, $url] = self::fresh(true);
        $canon = 'node//' . $url;
        $enc = rawurlencode($url);
        foreach ([
            "https://mesh.example.test/?viewmode=11&gotonode=$url&=",
            "https://mesh.example.test/?gotonode=$url",
            "https://mesh.example.test:444/dom/?login=abc&hide=31&gotonode=$url&viewmode=12&x=1#frag",
            "http://mesh.example.test/?viewmode=11&gotonode=$enc&=",
            "HTTPS://mesh.example.test/?gotonode=node%2F%2F$enc",
            "https://mesh.example.test/?gotonode=node//$url&viewmode=11",
            "https://mesh.example.test/?gotonode=$hex",
            "?viewmode=11&gotonode=$url&=",
            "  https://mesh.example.test/?gotonode=$url\n",
            "https://mesh.example.test/?gotonode=$url&gotonode=evil",
            "https://mesh.example.test/#?gotonode=$url",
        ] as $addr) {
            $this->assertSame($canon, N::normalize($addr), $addr);
        }
        $this->assertSame($url, N::toUrlForm("https://mesh.example.test/?viewmode=11&gotonode=$url&="));
    }

    public function testADomainIsKept(): void
    {
        [, $hex, $url] = self::fresh();
        $this->assertSame("node/acme/$url", N::normalize("node/acme/$hex"));
        $this->assertSame('acme', N::domain("node/acme/$url"));
        $this->assertSame($url, N::toUrlForm("node/acme/$url"));
        $this->assertSame($hex, N::toHex("node/acme/$url"));
        $this->assertNull(N::normalize("node/ac me/$url"));
        $this->assertNull(N::normalize("node/" . str_repeat('a', 33) . "/$url"));
        $this->assertNull(N::normalize("node/a/b/$url"));
    }

    public function testSpellingsCoverTheOldStoredForms(): void
    {
        [, $hex, $url] = self::fresh();
        $s = N::spellings($url);
        $this->assertContains('node//' . $url, $s);
        $this->assertContains('node//' . $hex, $s);
        $this->assertContains('node//' . strtoupper($hex), $s);
        $this->assertSame([], N::spellings('nonsense'));
    }

    public function testHostileAndMalformedInputIsRefused(): void
    {
        [, $hex, $url] = self::fresh();
        $bad = [
            '', ' ', 'node//', 'node/', 'node', substr($url, 1), $url . 'A', substr($hex, 2), $hex . '00', substr($url, 0, 63) . '!', substr($url, 0, 63) . '=',
            "$url\n$url", "node//$url\0", "node//$url\x07", "no de//$url", " $url x", "$url\t$url", "\x00$url", 'NODE//' . $url, 'Node//' . $url,
            str_repeat('g', 96), str_repeat('G', 96), str_repeat('A', 65), str_repeat('x', 5000), str_repeat('%41', 100),
            "ftp://mesh.example.test/?gotonode=$url", "javascript:alert(1)//?gotonode=$url", "file:///etc/passwd?gotonode=$url", 'https://mesh.example.test/?viewmode=11',
            'https://mesh.example.test/?gotonode=', 'https://mesh.example.test/?gotonode=short', "https://mesh.example.test/?gotonode=$url%00", "https://mesh.example.test/?gotonode=$url%0a$url",
            "https://mesh.example.test/?gotonode=$url%20", 'https:///?gotonode=' . $url, "https://mesh.example.test/?x=gotonode=$url", "https://mesh.example.test/?GOTONODE=$url",
            "../../etc/passwd", "node//../../$url", "node//$url/..", "node//$url?x", "'; DROP TABLE x;--", "<script>$url</script>", "node//\u{ff21}" . substr($url, 1),
        ];
        foreach ($bad as $in) {
            $this->assertNull(N::normalize($in), json_encode($in));
            $this->assertNull(N::toUrlForm($in));
            $this->assertNull(N::toHex($in));
        }
        // a trailing newline is not part of the id for the stored-value check, only for what a person pastes
        $this->assertNull(N::canonical("node//" . str_repeat('A', 24) . "\n"));
    }

    public function testLegacyValuesStillPassButAreNotInventedInto48Bytes(): void
    {
        foreach (['node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01', 'node//' . str_repeat('A', 16), 'node//' . str_repeat('z', 100)] as $legacy) {
            $this->assertNull(N::normalize($legacy));
            $this->assertSame($legacy, N::canonical($legacy));
            $this->assertTrue(N::valid($legacy));
        }
        foreach (['node//short', 'node//' . str_repeat('A', 101), 'node//' . str_repeat('!', 24), 'NODE//' . str_repeat('A', 24)] as $no) {
            $this->assertNull(N::canonical($no));
            $this->assertFalse(N::valid($no));
        }
    }

    public function testForLaunchEncodesAnythingOutsideTheAllowedSet(): void
    {
        [, $hex, $url] = self::fresh(true);
        $this->assertSame($url, N::forLaunch('node//' . $hex));
        $this->assertSame('AbCd%26hide%3D0%0D%0AHost%3A%20x', N::forLaunch("node//AbCd&hide=0\r\nHost: x"));
        $this->assertSame('x%2Fy', N::forLaunch('x/y'));
    }
}
