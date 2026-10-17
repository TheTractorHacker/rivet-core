<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\PackageSourcePolicyInterface;

/**
 * Conformance kit for {@see PackageSourcePolicyInterface}, the rule that decides where a device may be told to download an installer from. This is a security
 * boundary: a policy that is too generous turns "install this package" into "run whatever this address serves". What every policy must do: allow the address
 * the edition configured; answer a bool and never throw, whatever the text; answer the same to the same question; allow only `https`; never allow user
 * information, an IP address in any notation, a port other than the default, a fragment, a backslash, whitespace or a control character; compare the host as a whole
 * and without regard to case (never a prefix, a suffix or a substring); and not change any of this because of the client id it is given.
 *
 * Implement {@see policy()} and {@see allowedUrl()}: an https address with a plain DNS host name (such as `https://downloads.example.com/app/setup.msi`) that
 * the policy allows for client 7.
 *
 * @api
 */
abstract class PackageSourcePolicyConformanceTestCase extends TestCase
{
    abstract protected function policy(): PackageSourcePolicyInterface;

    /** An https address with a plain DNS host name that the policy allows (for any client the tests use). */
    abstract protected function allowedUrl(): string;

    /** @return array{0:string,1:string} [host, rest-after-host (path and query, may be empty)] */
    private function parts(): array
    {
        $url = $this->allowedUrl();
        $this->assertSame(1, preg_match('#^https://([a-z0-9.-]+)((?:/.*)?)$#D', $url, $m), 'allowedUrl() must be https://host/path with a lower-case DNS host name');

        return [$m[1], $m[2] === '' ? '/' : $m[2]];
    }

    private function allows(string $url, int $client = 7): bool
    {
        // The interface says bool, so PHP itself refuses any other answer (a TypeError fails the test that asked).
        return $this->policy()->allows($url, $client);
    }

    /** @param list<string> $urls */
    private function assertAllRefused(array $urls, string $why): void
    {
        foreach ($urls as $u) {
            $this->assertFalse($this->allows($u), "$why: " . json_encode($u, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }

    public function testTheConfiguredAddressIsAllowed(): void
    {
        $this->assertTrue($this->allows($this->allowedUrl()));
        [$host, $rest] = $this->parts();
        $this->assertTrue($this->allows("https://$host$rest?x=1&y=2"), 'a query string does not change the host');
    }

    public function testTheSameQuestionGetsTheSameAnswer(): void
    {
        [$host] = $this->parts();
        foreach ([$this->allowedUrl(), "http://$host/x", "https://u@$host/x"] as $u) {
            $answers = [];
            for ($i = 0; $i < 4; ++$i) {
                $answers[] = $this->allows($u);
            }
            $this->assertCount(1, array_unique($answers, SORT_REGULAR), 'asked four times, answered differently for ' . $u);
        }
    }

    public function testTheHostIsComparedWithoutRegardToCase(): void
    {
        [$host, $rest] = $this->parts();
        $this->assertTrue($this->allows('https://' . strtoupper($host) . $rest), 'host names are case-insensitive');
        $this->assertTrue($this->allows('https://' . ucfirst($host) . $rest));
    }

    public function testOnlyHttpsIsAllowed(): void
    {
        [$host, $rest] = $this->parts();
        $this->assertAllRefused(["http://$host$rest", "ftp://$host$rest", "file://$host$rest", "//$host$rest", "$host$rest", "javascript://$host$rest", "data://$host$rest", "https:/$host$rest",
            "https:$host$rest", "hxxps://$host$rest", "httpss://$host$rest", " https://$host$rest", "xhttps://$host$rest", ''], 'a scheme other than https');
    }

    public function testUserInformationIsNeverAllowed(): void
    {
        [$host, $rest] = $this->parts();
        $this->assertAllRefused(["https://user@$host$rest", "https://user:secret@$host$rest", "https://evil.example@$host$rest", "https://$host@evil.example$rest", "https://$host:pw@evil.example$rest",
            "https://@$host$rest", "https://$host%40evil.example@$host$rest"], 'user information');
    }

    public function testOnlyTheWholeHostMatches(): void
    {
        [$host, $rest] = $this->parts();
        $this->assertAllRefused(["https://$host.evil.example$rest", "https://evil.example.$host$rest", "https://x$host$rest", "https://sub.$host$rest", "https://{$host}x$rest", "https://$host.$rest",
            "https://$host-$rest", "https://" . substr($host, 1) . $rest, "https://" . substr($host, 0, -1) . $rest, "https://evil.example$rest", 'https://' . str_replace('.', '', $host) . $rest,
            "https://$host%2eevil.example$rest", "https://$host\u{200b}$rest", "https://$host\u{ff0e}evil.example$rest"], 'a host that is not exactly the configured one');
    }

    public function testNoIpAddressIsAllowedInAnyNotation(): void
    {
        $this->assertAllRefused(['https://127.0.0.1/a.msi', 'https://10.0.0.5/a.msi', 'https://192.168.1.1/a.msi', 'https://169.254.169.254/a.msi', 'https://[::1]/a.msi', 'https://[::ffff:127.0.0.1]/a.msi',
            'https://2130706433/a.msi', 'https://0x7f000001/a.msi', 'https://0x7f.0.0.1/a.msi', 'https://0177.0.0.1/a.msi', 'https://127.1/a.msi', 'https://8.8.8.8/a.msi', 'https://localhost/a.msi'], 'an IP address or a single-label host');
    }

    public function testOnlyTheDefaultPortIsAllowed(): void
    {
        [$host, $rest] = $this->parts();
        $this->assertAllRefused(["https://$host:8443$rest", "https://$host:80$rest", "https://$host:0$rest", "https://$host:99999$rest", "https://$host:$rest", "https://$host:443443$rest", "https://$host:abc$rest"], 'a port other than the default');
    }

    public function testNothingThatConfusesAParserIsAllowed(): void
    {
        [$host, $rest] = $this->parts();
        $this->assertAllRefused(["https://evil.example\\@$host$rest", "https://evil.example\\$host$rest", "https://$host\\@evil.example$rest", "https://$host\\evil$rest", "https://$host$rest#frag", "https://$host$rest#@evil.example/",
            "https://evil.example#@$host$rest", "https://evil.example?@$host$rest", "https://evil.example/https://$host$rest", "https://evil.example/?u=https://$host$rest", "https://$host\\$rest"], 'an address that different parsers read differently');
    }

    public function testWhitespaceAndControlCharactersAreRefused(): void
    {
        [$host, $rest] = $this->parts();
        $u = "https://$host$rest";
        $this->assertAllRefused([$u . "\n", $u . "\r\n", $u . ' ', ' ' . $u, "\t" . $u, $u . "\0", "https://$host\0.evil.example$rest", "https://$host$rest\x01", "https://$host$rest\x7f", "https://$host/a b", "https://$host/a\tb",
            "https://$host/a\nb", "https://$host/a\u{0085}b", "https://$host /a", "https://ho\nst$rest", "https://$host$rest\u{2028}"], 'whitespace or a control character');
    }

    public function testGarbageIsAnsweredWithABoolAndNeverThrows(): void
    {
        $garbage = ['', ' ', 'https://', 'https:///', 'https://:443/', 'https://.', 'https://..', str_repeat('a', 5000), 'https://' . str_repeat('a', 5000) . '/', 'https://' . str_repeat('a.', 2000) . 'com/',
            "\xff\xfe\xfd", "https://\xff\xfe/", 'https://' . str_repeat('%', 100), 'https://[', 'https://]', 'https://%', "https://\u{202e}moc.elpmaxe/", '0', 'null', '{}', "https://\u{1F600}.example/", 'ｈｔｔｐｓ://example.com/'];
        foreach ($garbage as $g) {
            $this->assertFalse($this->allows($g), 'garbage must be refused: ' . substr(json_encode($g, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '', 0, 60));
        }
    }

    public function testTheClientIdNeverLoosensTheRules(): void
    {
        [$host, $rest] = $this->parts();
        $bad = ["http://$host$rest", "https://u@$host$rest", 'https://127.0.0.1/a.msi', "https://$host.evil.example$rest", "https://$host$rest#f", 'https://evil.example/a.msi'];
        foreach ([0, 1, 7, 99999, PHP_INT_MAX, -1, PHP_INT_MIN] as $client) {
            foreach ($bad as $u) {
                $this->assertFalse($this->allows($u, $client), "client $client must not change the rules: $u");
            }
        }
    }
}
