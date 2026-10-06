<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Webhooks\Authentication;
use RivetCore\Webhooks\Destinations;
use RivetCore\Webhooks\PayloadFormatter;

final class DestinationsTest extends TestCase
{
    private const EXPECTED = [
        'n8n', 'node-red', 'activepieces', 'windmill', 'huginn', 'home-assistant', 'apprise', 'ntfy', 'gotify', 'discord', 'mattermost',
        'rocketchat', 'slack', 'teams', 'matrix-hookshot', 'matrix-client', 'telegram', 'zapier', 'make', 'pipedream', 'ifttt',
        'generic-json', 'generic-form', 'custom-template',
    ];

    public function testAllExpectedPresetsExistWithUniqueIds(): void
    {
        $ids = array_map(static fn ($d) => $d->id, Destinations::all());
        self::assertSame($ids, array_values(array_unique($ids)));
        foreach (self::EXPECTED as $id) {
            self::assertTrue(Destinations::has($id), $id);
            self::assertSame($id, Destinations::get($id)?->id);
        }
        self::assertNull(Destinations::get('nope'));
        self::assertFalse(Destinations::has('nope'));
    }

    public function testEveryPresetIsComplete(): void
    {
        $cats = array_keys(Destinations::categoryLabels());
        foreach (Destinations::all() as $d) {
            self::assertNotSame('', $d->name, $d->id);
            self::assertContains($d->category, $cats, $d->id);
            self::assertNotSame('', $d->description, $d->id);
            self::assertTrue(PayloadFormatter::isFormat($d->format), $d->id . ' format');
            self::assertContains($d->method, ['POST', 'PUT'], $d->id);
            self::assertMatchesRegularExpression('#^https?://#', $d->urlHint, $d->id);
            self::assertNotSame([], $d->authModes, $d->id);
            self::assertContains($d->defaultAuth, $d->authModes, $d->id);
            self::assertSame([], array_diff($d->authModes, Authentication::MODES), $d->id);
            self::assertNotSame('', $d->docsUrl, $d->id);
            self::assertGreaterThanOrEqual(3, count($d->setupSteps), $d->id);
            self::assertStringContainsString('curl', $d->sampleCurl, $d->id);
            self::assertNotSame([], $d->notes, $d->id);
            if ($d->defaultAuth === 'header') {
                self::assertNotNull($d->defaultAuthHeader, $d->id);
            }
            foreach ($d->extraFields as $f) {
                self::assertNotSame('', $f->name);
                self::assertNotSame('', $f->label);
                self::assertContains($f->type, ['text', 'number', 'secret']);
                self::assertContains($f->target, ['url', 'option']);
                if ($f->target === 'url') {
                    self::assertStringContainsString('{' . $f->name . '}', $d->urlHint, $d->id . ' ' . $f->name);
                } else {
                    self::assertNotSame('', $f->option, $d->id . ' ' . $f->name);
                }
            }
            foreach ($d->headers as $k => $v) {
                self::assertTrue(Authentication::isValidHeaderName($k) && !Authentication::isForbiddenHeaderName($k));
                self::assertDoesNotMatchRegularExpression('/[\r\n]/', $v);
            }
            if ($d->format === 'template') {
                self::assertSame([], PayloadTemplate_validate($d), $d->id . ' default template');
            }
        }
    }

    public function testSignatureVerificationSnippetsPresentForAutomationAndGeneric(): void
    {
        foreach (['n8n', 'node-red', 'windmill', 'pipedream', 'generic-json', 'generic-form', 'custom-template'] as $id) {
            $s = Destinations::get($id)?->verifySnippets ?? [];
            foreach (['node', 'python', 'php', 'bash'] as $lang) {
                self::assertArrayHasKey($lang, $s, "$id $lang");
                self::assertStringContainsString('300', $s[$lang]);
            }
        }
        self::assertArrayHasKey('n8n-code', Destinations::get('n8n')->verifySnippets);
        self::assertSame([], Destinations::get('slack')->verifySnippets);
    }

    public function testUrlPatternsAndHintsAreSane(): void
    {
        foreach (Destinations::all() as $d) {
            if ($d->urlPattern !== null) {
                self::assertNotFalse(@preg_match($d->urlPattern, ''), $d->id . ' pattern compiles');
            }
        }
        $good = [
            'discord' => 'https://discord.com/api/webhooks/1234567890/abc-DEF_ghi',
            'slack' => 'https://hooks.slack.com/services/T0AAAA/B0BBBB/xyzXYZ123',
            'teams' => 'https://prod-12.westus.logic.azure.com:443/workflows/abc/triggers/manual/paths/invoke?api-version=2016-06-01&sig=x',
            'telegram' => 'https://api.telegram.org/bot123456:ABC-def_1/sendMessage',
            'zapier' => 'https://hooks.zapier.com/hooks/catch/12345/abcde/',
            'make' => 'https://hook.eu1.make.com/abcdef123456',
            'pipedream' => 'https://eo12abc.m.pipedream.net',
            'ifttt' => 'https://maker.ifttt.com/trigger/ticket/with/key/abcDEF123',
            'mattermost' => 'https://mm.example.com/hooks/abc123def456',
            'rocketchat' => 'https://chat.example.com/hooks/ID123/token456',
            'home-assistant' => 'http://ha.local:8123/api/webhook/my-long-id_123',
            'matrix-hookshot' => 'https://hookshot.example.com/webhook/0b0d-aaaa',
            'n8n' => 'https://n8n.example.com/webhook/abc',
        ];
        foreach ($good as $id => $url) {
            self::assertTrue(Destinations::get($id)->urlMatches($url), $id);
        }
        self::assertFalse(Destinations::get('discord')->urlMatches('https://evil.example/api/webhooks/1/x'));
        self::assertFalse(Destinations::get('slack')->urlMatches('https://hooks.slack.com.evil.example/services/T/B/x'));
        self::assertFalse(Destinations::get('telegram')->urlMatches('https://api.telegram.org.evil.example/bot1:a/sendMessage'));
        self::assertFalse(Destinations::get('n8n')->urlMatches('ftp://x/y'));
        self::assertFalse(Destinations::get('n8n')->urlMatches('https://x/y z'));
    }

    public function testBuildUrlFillsAndEncodesPlaceholders(): void
    {
        $m = Destinations::get('matrix-client');
        self::assertSame(
            'https://matrix.example.org/_matrix/client/v3/rooms/%21abc:example.org/send/m.room.message/{txn}',
            $m->buildUrl(['room_id' => '!abc:example.org'])
        );
        self::assertSame('https://ntfy.sh/my%20topic', Destinations::get('ntfy')->buildUrl(['topic' => 'my topic']));
        self::assertTrue(Destinations::get('telegram')->urlMatches(Destinations::get('telegram')->buildUrl(['bot_token' => '123456:abc-DEF'])));
        self::assertSame('PUT', $m->method);
    }

    public function testCatalogSerialisesToJson(): void
    {
        $j = json_decode(Destinations::toJson(), true);
        self::assertCount(count(self::EXPECTED), $j['destinations']);
        self::assertSame(array_keys(Destinations::categoryLabels()), array_keys($j['categories']));
        self::assertSame(count(Destinations::all()), array_sum(array_map('count', Destinations::byCategory())));
    }
}

function PayloadTemplate_validate(\RivetCore\Webhooks\Destination $d): array
{
    return \RivetCore\Webhooks\PayloadTemplate::validate((string) $d->formatOptions['template'], (string) $d->formatOptions['template_encoding']);
}
