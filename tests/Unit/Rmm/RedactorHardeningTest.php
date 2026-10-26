<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Crypto\Redactor;

/** CORE-06: the job-output redactor must stay linear, must not hand text back when PCRE fails, and masks the names the change monitor masks. */
final class RedactorHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        ini_set('pcre.jit', '1');
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function hugeShapes(): iterable
    {
        yield 'quoted value of 20 KB' => ['client_secret="' . str_repeat('A', 20000) . '"', str_repeat('A', 8)];
        yield 'single quoted value of 1 MB' => ["api_key='" . str_repeat('B', 1_000_000) . "'", str_repeat('B', 8)];
        yield 'escape heavy value' => ['password="' . str_repeat('\\"', 30000) . '"', '\\"\\"\\"\\"'];
        yield 'unterminated quote' => ['token="' . str_repeat('C', 50000), str_repeat('C', 8)];
    }

    #[DataProvider('hugeShapes')]
    public function testLargeQuotedValuesAreStillMasked(string $in, string $mustBeGone): void
    {
        $out = Redactor::redact($in);
        $this->assertStringNotContainsString($mustBeGone, $out);
        $this->assertStringContainsString(Redactor::MASK, $out);
    }

    public function testPathologicalNameRunsFinishQuickly(): void
    {
        foreach ([str_repeat('x-token-', 31000), str_repeat('password.', 28000), str_repeat('a.a.', 60000), str_repeat('pass-', 50000)] as $in) {
            $t = microtime(true);
            Redactor::redact($in);
            $this->assertLessThan(2.0, microtime(true) - $t, 'strlen ' . strlen($in));
        }
    }

    public function testAPcreFailureWithholdsTheTextInsteadOfReturningIt(): void
    {
        $old = ini_get('pcre.backtrack_limit');
        $oldJit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $out = Redactor::redact('hello password=hunter2hunter2 ' . str_repeat('padding ', 200));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $old);
            ini_set('pcre.jit', (string) $oldJit);
        }
        $this->assertSame(Redactor::MASK, $out);
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function names(): iterable
    {
        yield 'DB_PASS' => ['DB_PASS=hunter22hunter', 'hunter22hunter'];
        yield 'SMTP_PASS colon' => ['SMTP_PASS: hunter22hunter', 'hunter22hunter'];
        yield 'REDIS_PW' => ['REDIS_PW=hunter22hunter', 'hunter22hunter'];
        yield 'APP_KEY' => ['APP_KEY=base64:abc123abc123abc123', 'abc123abc123abc123'];
        yield 'dotted key' => ['api.key=hunter22hunter', 'hunter22hunter'];
        yield 'camel case' => ['licenseKey = "hunter22 hunter"', 'hunter22'];
        yield 'curl -u' => ['curl -s -u admin:hunter22hunter https://x', 'hunter22hunter'];
        yield 'mysql -p attached' => ['mysql -u root -phunter22hunter db', 'hunter22hunter'];
        yield 'sshpass' => ['sshpass -p hunter22hunter ssh host', 'hunter22hunter'];
    }

    #[DataProvider('names')]
    public function testNamesTheChangeMonitorMasksAreMaskedInJobOutputToo(string $in, string $gone): void
    {
        $this->assertStringNotContainsString($gone, Redactor::redact($in));
    }

    /** @return iterable<string,array{0:string}> */
    public static function harmless(): iterable
    {
        yield 'passage' => ['the passage was long'];
        yield 'keyboard' => ['keyboard layout: us'];
        yield 'ls -p' => ['ls -p /tmp'];
        yield 'public key word' => ['monkey = 3'];
        yield 'plain' => ['Status: Running, 3 services'];
    }

    #[DataProvider('harmless')]
    public function testOrdinaryTextIsUntouched(string $in): void
    {
        $this->assertSame($in, Redactor::redact($in));
    }
}
