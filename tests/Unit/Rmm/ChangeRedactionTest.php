<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Change\ChangeProtocol;

/**
 * rc.31 security review F2 and F4: what a change record's text may keep, and what may reach an alert or a webhook. The redaction rows are the vectors the Go agent
 * replays too (endpoint-agent/testdata/vectors/agent_change_monitor_rc31_vectors.json, "redaction").
 */
final class ChangeRedactionTest extends TestCase
{
    public function testTheServerGivesTheSameTextAsTheVectorsTheAgentReplays(): void
    {
        $rows = RmmVectors::load('agent_change_monitor_rc31_vectors.json')['redaction'];
        $this->assertGreaterThanOrEqual(30, count($rows));
        foreach ($rows as $r) {
            $this->assertSame($r['output'], ChangeProtocol::redact(ChangeProtocol::text($r['input'], 2000)), $r['input']);
        }
    }

    public function testTheServerGivesTheSameTextAsTheRc38VectorsTheAgentReplays(): void
    {
        $rows = RmmVectors::load('agent_change_monitor_rc38_vectors.json')['redaction'];
        $this->assertGreaterThanOrEqual(25, count($rows));
        foreach ($rows as $r) {
            $this->assertSame($r['output'], ChangeProtocol::redact(ChangeProtocol::text($r['input'], 2000)), $r['input']);
        }
    }

    /** rc.38, security review RC36 L1: the cases the review proved. */
    public function testQuotedValuesWithSpacesAreMaskedToTheClosingQuote(): void
    {
        $this->assertSame('pass="[REDACTED]"', ChangeProtocol::redact('pass="a b c"'));
        $this->assertSame('PASSWORD="[REDACTED]"', ChangeProtocol::redact('PASSWORD="quoted value"'));
        $this->assertSame("api_key='[REDACTED]' x", ChangeProtocol::redact("api_key='one two' x"));
        $this->assertSame('{"password": "[REDACTED]", "user": "bob"}', ChangeProtocol::redact('{"password": "two words", "user": "bob"}'));
        // an unclosed quote never swallows the next line
        $this->assertSame("pass=\"[REDACTED]\nnext line stays", ChangeProtocol::redact("pass=\"open ended\nnext line stays"));
    }

    public function testSpaceSeparatedSecretDirectivesAreMaskedButPamLinesAreNot(): void
    {
        foreach (['DB_PASSWORD hunter2' => 'hunter2', 'token hunter2value' => 'hunter2value', 'requirepass x1y2z3' => 'x1y2z3', "masterauth s3cret\nmaxmemory 2gb" => 's3cret', 'psk=hunter2hunter2' => 'hunter2hunter2', 'Passw0rd=abc123' => 'abc123'] as $in => $secret) {
            $this->assertStringNotContainsString($secret, ChangeProtocol::redact($in), $in);
        }
        $this->assertStringContainsString('maxmemory 2gb', ChangeProtocol::redact("masterauth s3cret\nmaxmemory 2gb"));
        foreach (['auth required pam_unix.so', 'password requisite pam_pwquality.so retry=3', 'password sufficient pam_unix.so sha512', 'passenger_root /usr/lib/passenger'] as $t) {
            $this->assertSame($t, ChangeProtocol::redact($t), $t);
        }
    }

    public function testThePanelAndEnrollmentCredentialsAreMaskedWhole(): void
    {
        $dev = 'rvpd_' . str_repeat('0123456789abcdef', 4);
        $tok = 'rvtp1.0123456789ab.' . str_repeat('0123456789abcdef', 2) . '01234567';
        $this->assertSame('[REDACTED]', ChangeProtocol::redact($dev));
        $this->assertSame('x [REDACTED] y', ChangeProtocol::redact("x $dev y"));
        $this->assertSame('use [REDACTED] now', ChangeProtocol::redact("use $tok now"));
        $this->assertSame('[REDACTED]', \RivetCore\Rmm\Crypto\Redactor::redact($dev), 'the shared job-output redactor masks it too');
        $this->assertSame('a [REDACTED] b', \RivetCore\Rmm\Crypto\Redactor::redact("a $tok b"));
        $this->assertSame('[REDACTED]', \RivetCore\Rmm\Crypto\Redactor::redact('rvte1.0123456789ab.' . str_repeat('0123456789abcdef', 2) . '01234567'));
    }

    public function testTheSharedRedactorMasksAQuotedPhraseToo(): void
    {
        $this->assertSame('PASSWORD="[REDACTED]"', \RivetCore\Rmm\Crypto\Redactor::redact('PASSWORD="quoted value"'));
        $this->assertSame('Authorization: [REDACTED]', \RivetCore\Rmm\Crypto\Redactor::redact('Authorization: Basic abc123'));
    }

    public function testAlertTextBreaksMarkdownLinksImagesAndMassMentions(): void
    {
        $t = ChangeProtocol::alertText('[Reset your password](https://evil.example/login) and ![i](https://evil/p.png)', 200);
        $this->assertStringNotContainsString('[', $t);
        $this->assertStringNotContainsString('](', $t);
        $this->assertStringNotContainsString('![', $t);
        foreach (['@everyone urgent', '@here now', '@channel', 'x@everyone', '@Everyone', '@bob please'] as $in) {
            $this->assertStringNotContainsString('@', ChangeProtocol::alertText($in, 200), $in);
        }
        $this->assertStringContainsString('everyone urgent', ChangeProtocol::alertText('@everyone urgent'), 'the words stay readable');
        $this->assertStringContainsString('ops@example.com', ChangeProtocol::alertText('MAILTO ops@example.com'), 'an address is not a mention');
        $this->assertStringContainsString('Program Files (x86)', ChangeProtocol::alertText('Program Files (x86)'), 'round brackets stay');
        $this->assertStringNotContainsString('&lt;', ChangeProtocol::alertText('x&lt;script&gt;'), 'an entity is not rebuilt into a tag by a later decoder');
        $this->assertStringNotContainsString('<!', ChangeProtocol::alertText('<!channel> go'));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function secrets(): array
    {
        return [
            'systemd Environment DB_PASS' => ['Environment="DB_PASS=s3cret"', 's3cret'],
            'SMTP_PASS' => ['SMTP_PASS=hunter2', 'hunter2'],
            'REDIS_PASS' => ['REDIS_PASS=hunter2', 'hunter2'],
            'ENCRYPTION_KEY' => ['ENCRYPTION_KEY=abc123xyz', 'abc123xyz'],
            'AUTH_KEY' => ['AUTH_KEY=abc123xyz', 'abc123xyz'],
            'LICENSE_KEY' => ['LICENSE_KEY=AAAA-BBBB-CCCC', 'AAAA-BBBB-CCCC'],
            'MASTER_KEY' => ['MASTER_KEY: abc123xyz', 'abc123xyz'],
            'space separated --pass' => ['/usr/bin/app --pass hunter2', 'hunter2'],
            'space separated --token' => ['/usr/bin/app --token abc123xyz', 'abc123xyz'],
            'mysql -p word' => ['mysql -u root -p hunter2', 'hunter2'],
            'unknown name in Environment=' => ['Environment=WIDGET=topsecretvalue', 'topsecretvalue'],
            'long hex value' => ['id a3f5c9d8e7b6a5f4c3d2e1f0a9b8c7d6e5f4a3b2', 'a3f5c9d8e7b6a5f4c3d2e1f0a9b8c7d6e5f4a3b2'],
        ];
    }

    /** @dataProvider secrets */
    #[\PHPUnit\Framework\Attributes\DataProvider('secrets')]
    public function testSecretsAreMasked(string $text, string $secret): void
    {
        $out = ChangeProtocol::redact($text);
        $this->assertStringNotContainsString($secret, $out);
        $this->assertStringContainsString(ChangeProtocol::MASK, $out);
    }

    public function testHarmlessTextIsLeftAlone(): void
    {
        foreach (['ExecStart=/usr/bin/app --key-file /etc/app/key --port 80', 'Environment=LANG=C.UTF-8 PATH=/usr/bin', 'psql -h db -p 5432', 'MAILTO=ops@example.com', 'user=alice',
            'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGl6kqVx0z9u8B2cV7yH5nQe1sT4wXjR3aZpLmKdOfUb alice@host', 'HKLM\\SOFTWARE\\Acme\\Agent'] as $t) {
            $this->assertSame($t, ChangeProtocol::redact($t), $t);
        }
        // the sudoers tag is still not taken for a password
        $this->assertSame('alice ALL=(ALL) NOPASSWD: ALL', ChangeProtocol::redact('alice ALL=(ALL) NOPASSWD: ALL', true));
    }

    public function testAlertTextRemovesMarkupQuotesBacktickAndBidiButKeepsRegistryPaths(): void
    {
        $t = ChangeProtocol::alertText("HKU\\S-1\\Run <img src=x onerror=alert(1)> `id` \"q\" 'q' \u{202e}evil\u{200b}\u{feff} \x07 end", 200);
        foreach (['<', '>', '`', '"', "'", "\u{202e}", "\u{200b}", "\u{feff}", "\x07"] as $bad) {
            $this->assertStringNotContainsString($bad, $t);
        }
        $this->assertStringContainsString('HKU\\S-1\\Run', $t);
        $this->assertStringContainsString('img src=x onerror=alert(1)', $t, 'the words stay readable; only what makes markup is removed');
        $this->assertSame('', ChangeProtocol::alertText("<>`", 50));
        $this->assertSame(5, mb_strlen(ChangeProtocol::alertText(str_repeat('a ', 50), 5)));
    }

    public function testStoredTextAlsoLosesBidiAndZeroWidthMarks(): void
    {
        $this->assertSame('a b c', ChangeProtocol::text("a\u{202e}b\u{200b}c", 20));
    }
}
