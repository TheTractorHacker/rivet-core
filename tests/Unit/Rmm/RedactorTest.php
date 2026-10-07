<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Crypto\Redactor;

final class RedactorTest extends TestCase
{
    public function testMaskIsTheFrozenOne(): void
    {
        $this->assertSame('[REDACTED]', Redactor::MASK);
    }

    public function testOrdinaryTextIsUntouched(): void
    {
        foreach (['plain text with no secrets', '', "line one\nline two", 'Status: Running', 'tokenless text about monkeys', 'a 63 hex ' . str_repeat('a', 63)] as $s) {
            $this->assertSame($s, Redactor::redact($s));
        }
    }

    /** @return array<string,array{0:string,1:string}> [input, needle that must be gone] */
    public static function secrets(): array
    {
        return [
            'bearer' => ['Bearer abcdefghijklmnop1234567890', 'abcdefghijklmnop1234567890'],
            'bearer lower case' => ['bearer abcdefgh12345678', 'abcdefgh12345678'],
            'password kv' => ['password=Sup3rS3cret!', 'Sup3rS3cret'],
            'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U', 'dozjgNryP4J3jVmNHl0w5N'],
            'sha256 hex' => [str_repeat('ab', 32), str_repeat('ab', 32)],
            'pem' => ["-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBg\n-----END PRIVATE KEY-----", 'MIIEvQIBADANBg'],
            'pem rsa unterminated' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA", 'MIIEowIBAAKCAQEA'],
            'securestring' => ['ConvertTo-SecureString "hunter2hunter2" -AsPlainText', 'hunter2hunter2'],
            'securestring -String' => ["ConvertTo-SecureString -String 'hunter3hunter3'", 'hunter3hunter3'],
            'aws key' => ['api_key: AKIAIOSFODNN7EXAMPLE', 'AKIAIOSFODNN7EXAMPLE'],
            'aws key bare' => ['id AKIAIOSFODNN7EXAMPLE end', 'AKIAIOSFODNN7EXAMPLE'],
            'client secret' => ['client_secret=zzzzzzzz', 'zzzzzzzz'],
            'github token' => ['ghp_' . 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7', 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7'],
            'slack token' => ['xoxb-1234567890-abcdefghij', '1234567890-abcdefghij'],
            'enroll token' => ['rvte1.' . 'abcdef012345' . '.' . str_repeat('b', 40), str_repeat('b', 40)],
            'authorization header' => ['Authorization: Basic dXNlcjpwYXNz', 'dXNlcjpwYXNz'],
            'proxy authorization' => ['Proxy-Authorization = NTLM TlRMTVNTUA', 'TlRMTVNTUA'],
            'powershell -Password' => ['Invoke-X -Password hunter4hunter4', 'hunter4hunter4'],
            'powershell -Token quoted' => ["Invoke-X -Token 'tok-4444444'", 'tok-4444444'],
            'quoted json secret' => ['{"api_key": "k-123456789"}', 'k-123456789'],
            'private_key name' => ['private-key=pk999999', 'pk999999'],
            'credential name' => ['MyCredential: c0mplexvalue', 'c0mplexvalue'],
        ];
    }

    #[DataProvider('secrets')]
    public function testSecretsAreRemoved(string $input, string $needle): void
    {
        $out = Redactor::redact("start\n$input\nend");
        $this->assertStringNotContainsString($needle, $out);
        $this->assertStringContainsString('[REDACTED]', $out);
        $this->assertStringStartsWith("start\n", $out);
        if ($input !== "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA") {
            $this->assertStringEndsWith("\nend", $out);
        }
    }

    public function testKeyNamesAreKeptAndValuesMasked(): void
    {
        $this->assertSame('password=[REDACTED]', Redactor::redact('password=Sup3rS3cret!'));
        $this->assertSame('api_key: [REDACTED]', Redactor::redact('api_key: AKIAIOSFODNN7EXAMPLE'));
        $this->assertSame('Bearer [REDACTED]', Redactor::redact('Bearer abcdefghijklmnop1234567890'));
        $this->assertSame('Authorization: [REDACTED]', Redactor::redact('Authorization: Basic dXNlcjpwYXNz'));
        $this->assertSame('x [REDACTED] y', Redactor::redact('x ' . str_repeat('0f', 32) . ' y'));
    }

    public function testRedactionOfBulkOutput(): void
    {
        $noisy = "start\nBearer abcdefghijklmnop1234567890\n" . str_repeat("line of output\n", 12000);
        $out = Redactor::redact($noisy);
        $this->assertStringNotContainsString('abcdefghijklmnop1234567890', $out);
        $this->assertStringStartsWith("start\n", $out);
        $this->assertStringEndsWith(str_repeat("line of output\n", 12000), $out);
    }
}
