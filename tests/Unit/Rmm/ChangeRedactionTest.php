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
