<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Panel;

use PHPUnit\Framework\TestCase;
use RivetCore\Panel\PanelLimits;
use RivetCore\Panel\PanelProtocol;

/** Structural checks of docs/panel (no YAML parser): paths, references, error codes and the numbers the prose quotes. */
final class PanelDocsTest extends TestCase
{
    private string $yaml = '';
    private string $md = '';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3) . '/docs/panel';
        $this->yaml = (string) file_get_contents($root . '/openapi-panel.yaml');
        $this->md = (string) file_get_contents($root . '/PANEL_API.md');
    }

    public function testTheSpecIsOpenApi31WithEveryPanelPath(): void
    {
        $this->assertStringStartsWith("openapi: 3.1.0\n", $this->yaml);
        foreach (['/panel_enroll', '/panel_me', '/panel_categories', '/panel_tickets', '/panel_ticket_chat'] as $path) {
            $this->assertMatchesRegularExpression('#^  ' . preg_quote($path, '#') . ':$#m', $this->yaml, $path);
            $this->assertStringContainsString($path, $this->md);
        }
        $this->assertStringNotContainsString('nullable: true', $this->yaml);
    }

    public function testEveryReferenceResolves(): void
    {
        preg_match_all('~\#/components/(\w+)/(\w+)~', $this->yaml, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as [, $kind, $name]) {
            $this->assertMatchesRegularExpression('/^    ' . preg_quote($name, '/') . ':/m', $this->yaml, "#/components/$kind/$name is not defined");
        }
    }

    public function testEveryErrorCodeTheCodeEmitsIsInTheEnumAndTheErrorTable(): void
    {
        $this->assertSame(1, preg_match('/code:\n\s+type: string\n.*?enum: \[(.*?)\]/s', $this->yaml, $m));
        $enum = array_map('trim', explode(',', $m[1]));
        $src = '';
        foreach (glob(dirname(__DIR__, 3) . '/src/Panel/{,Http/}*.php', GLOB_BRACE) ?: [] as $f) {
            $src .= (string) file_get_contents($f);
        }
        preg_match_all("/new ApiError\\(\\d+, '([a-z_]+)'/", $src, $codes);
        $emitted = array_unique(array_merge($codes[1], ['module_disabled', 'internal']));
        $this->assertNotEmpty($emitted);
        foreach ($emitted as $code) {
            $this->assertContains($code, $enum, $code);
            $this->assertStringContainsString("`$code`", $this->md, $code);
        }
    }

    public function testTheNumbersInTheProseAreTheCodesValues(): void
    {
        $this->assertSame([120, 60], PanelProtocol::RATE_READ);
        $this->assertSame([10, 60], PanelProtocol::RATE_CREATE);
        $this->assertSame([20, 60], PanelProtocol::RATE_CHAT);
        $this->assertSame([30, 60], PanelProtocol::RATE_AUTH_FAIL);
        $flat = (string) preg_replace('/\s+/', ' ', $this->md);
        foreach (['120 per 60 s', '10 per 60 s', '20 per 60 s', '30 per 60 s', '10 failures or 60 attempts per 600 s', '365 days', '72 h at most'] as $s) {
            $this->assertStringContainsString($s, $flat, $s);
        }
        $l = new PanelLimits();
        $this->assertSame([200, 20000, 5000, 3, 50], [$l->subjectMax, $l->ticketBodyMax, $l->chatBodyMax, $l->attachmentsMax, $l->ticketsPerDayMax]);
        $this->assertStringContainsString('subject 200 characters, body 20000', $flat);
        $this->assertStringContainsString('Body limit 5000', $flat);
        $this->assertSame(365, PanelProtocol::DEVICE_TOKEN_VALID_DAYS);
        $this->assertSame(72, PanelProtocol::ENROLL_MAX_TTL_H);
    }

    public function testTheExamplesInTheProseAreValidJson(): void
    {
        preg_match_all('/```json\n(.*?)\n```/s', $this->md, $m);
        $this->assertGreaterThanOrEqual(6, count($m[1]));
        foreach ($m[1] as $block) {
            $block = (string) preg_replace('/<[^>]+>/', 'x', $block);
            $block = str_replace('...', 'x', $block);
            $this->assertNotNull(json_decode($block, true), $block);
        }
    }
}
