<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Webhooks\Destinations;

final class WebhookDocsTest extends TestCase
{
    public function testPlatformGuideIsNotStale(): void
    {
        $root = dirname(__DIR__, 2);
        $generated = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/webhook-guides.php') . ' 2>&1');
        self::assertSame(
            $generated,
            (string) file_get_contents($root . '/docs/webhook-platforms.md'),
            'docs/webhook-platforms.md is stale: run `php scripts/webhook-guides.php > docs/webhook-platforms.md`'
        );
    }

    public function testEveryPresetHasASectionAndWebhooksGuideShowsTheSnippets(): void
    {
        $root = dirname(__DIR__, 2);
        $platforms = (string) file_get_contents($root . '/docs/webhook-platforms.md');
        foreach (Destinations::all() as $d) {
            self::assertStringContainsString('<a id="' . $d->id . '"></a>', $platforms, $d->id);
        }
        $guide = (string) file_get_contents($root . '/docs/webhooks.md');
        $snippets = Destinations::get('generic-json')->verifySnippets;
        foreach (['node', 'python', 'php', 'bash', 'n8n-code'] as $lang) {
            self::assertStringContainsString(trim($snippets[$lang]), $guide, "webhooks.md is missing the $lang verification snippet");
        }
        foreach (['300 seconds', 'X-Rivet-Signature-V2', 'backoff', 'allowedNetworks'] as $needle) {
            self::assertStringContainsString($needle, $guide);
        }
    }
}
