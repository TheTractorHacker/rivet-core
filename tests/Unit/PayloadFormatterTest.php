<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Webhooks\EventSummary;
use RivetCore\Webhooks\FormattedPayload;
use RivetCore\Webhooks\PayloadFormatter;
use RivetCore\Webhooks\PayloadTemplate;

final class PayloadFormatterTest extends TestCase
{
    private const TS = '2026-01-02T03:04:05Z';
    private const TYPES = [
        'ticket.created', 'ticket.replied', 'ticket.resolved', 'sla.breached', 'approval.requested', 'workflow.onboarding_started',
        'workflow.action_failed', 'asset.warranty_expiring', 'auth.login_failed', 'backup.failed', 'invoice.overdue',
        'vault.credential_revealed', 'custom.thing_happened',
    ];
    private const JSON_FORMATS = ['slack', 'slack_attachments', 'teams', 'discord', 'gotify', 'telegram', 'matrix', 'matrix_hookshot', 'apprise'];

    /** @return array<string,mixed> */
    private static function opts(): array
    {
        return ['chat_id' => '-1001234567890', 'app_name' => 'RivetIT', 'template' => '{"t":"{{summary.title}}","s":"{{summary.summary}}"}'];
    }

    /** @return array<string,mixed> */
    private static function event(string $type, ?array $data = null): array
    {
        return ['event' => $type, 'timestamp' => self::TS, 'data' => $data ?? PayloadTemplate::sampleContext($type)['data']];
    }

    /** @return array<string,mixed> */
    private static function hostile(): array
    {
        return [
            'ticket_number' => "TCK-1\x00", 'ticket_subject' => "<script>alert(1)</script> <!channel> <@U123> @everyone @here [x](http://evil.example) *b* `c` _i_ ~s~ |p| \x00\x1b\u{202E} \u{1F600} \u{00e9}\u{4e2d}\u{6587} \"q\" 'a' \\ &amp; " . str_repeat('A', 100000),
            'ticket_priority' => 'Critical', 'ticket_status' => '<b>Open</b>', 'client_name' => str_repeat('C', 5000) . "\n\r\nInjected: 1",
            'assigned_to_user_name' => "Bob\r\nX-Evil: 1", 'summary' => str_repeat("\u{1F4A5}", 6000), 'action' => '<i>x</i>', 'entity_type' => '`y`',
            'password' => 'S3CRET-VALUE', 'api_token' => 'S3CRET-VALUE', 'nested' => ['password' => 'S3CRET-VALUE', 'a' => 'ok'],
            'url' => 'javascript:alert(1)', 'bad_utf8' => "\xC3\x28 bad",
        ];
    }

    public function testJsonFormatIsByteIdenticalToTheLegacyEnvelope(): void
    {
        $data = ['ticket_id' => 1, 'ticket_subject' => 'héllo/wörld 😀', 'tags' => ['a', 'b'], 'empty' => [], 'f' => 1.5, 'n' => null, 'b' => true];
        $legacy = json_encode(['event' => 'ticket.created', 'timestamp' => self::TS, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $p = PayloadFormatter::format('json', ['event' => 'ticket.created', 'timestamp' => self::TS, 'data' => $data]);
        self::assertSame($legacy, $p->body);
        self::assertSame('application/json', $p->contentType);
        self::assertSame([], $p->headers);
        self::assertSame('{"event":"ticket.created","timestamp":"2026-01-02T03:04:05Z","data":{"ticket_id":1,"ticket_subject":"héllo/wörld 😀","tags":["a","b"],"empty":[],"f":1.5,"n":null,"b":true}}', $p->body);
    }

    public function testJsonFormatSurvivesInvalidUtf8(): void
    {
        $p = PayloadFormatter::format('json', self::event('x.y', ['bad' => "\xC3\x28"]));
        self::assertNotNull(json_decode($p->body, true));
    }

    /** @return iterable<string,array{string}> */
    public static function typeProvider(): iterable
    {
        foreach (self::TYPES as $t) {
            yield $t => [$t];
        }
    }

    #[DataProvider('typeProvider')]
    public function testEveryFormatProducesValidOutputForSampleEvents(string $type): void
    {
        foreach (PayloadFormatter::FORMATS as $format) {
            $p = PayloadFormatter::format($format, self::event($type), self::opts());
            self::assertInstanceOf(FormattedPayload::class, $p);
            self::assertNotSame('', $p->body, "$format $type");
            if (in_array($format, self::JSON_FORMATS, true) || $format === 'json') {
                self::assertIsArray(json_decode($p->body, true), "$format $type");
                self::assertSame(JSON_ERROR_NONE, json_last_error());
            }
        }
        $t = PayloadFormatter::format('template', self::event($type), self::opts());
        self::assertSame('application/json; charset=utf-8', $t->contentType);
        self::assertIsArray(json_decode($t->body, true));
    }

    public function testHostileContentIsContainedInEveryFormat(): void
    {
        $event = self::event('ticket.created', self::hostile());
        foreach (PayloadFormatter::FORMATS as $format) {
            $p = PayloadFormatter::format($format, $event, self::opts());
            if ($format !== 'json') {
                self::assertStringNotContainsString('S3CRET-VALUE', $p->body, $format);
                self::assertStringNotContainsString("\0", $p->body, $format);
                self::assertStringNotContainsString("\x1b", $p->body, $format);
                self::assertLessThan(120000, strlen($p->body), $format . ' size');
            }
            foreach ($p->headers as $k => $v) {
                self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $k . $v, "$format header");
            }
            if (in_array($format, self::JSON_FORMATS, true)) {
                self::assertIsArray(json_decode($p->body, true), $format);
            }
        }
    }

    public function testSlackLimitsAndEscaping(): void
    {
        $j = json_decode(PayloadFormatter::format('slack', self::event('ticket.created', self::hostile()))->body, true);
        self::assertLessThanOrEqual(150, mb_strlen($j['blocks'][0]['text']['text']));
        self::assertLessThanOrEqual(3000, mb_strlen($j['blocks'][1]['text']['text']));
        self::assertLessThanOrEqual(10, count($j['blocks'][1]['fields'] ?? []));
        self::assertStringNotContainsString('<', $j['text']);
        self::assertStringNotContainsString('@everyone', $j['text']);
        self::assertStringContainsString("@\u{200B}everyone", $j['text']);
        self::assertFalse($j['mrkdwn']);
        self::assertNull($j['blocks'][2]['elements'][0]['url'] ?? null, 'javascript: url is dropped');
    }

    public function testSlackAttachmentsForMattermostAndRocketChat(): void
    {
        $j = json_decode(PayloadFormatter::format('slack_attachments', self::event('ticket.created', self::hostile()))->body, true);
        self::assertArrayNotHasKey('blocks', $j);
        $a = $j['attachments'][0];
        self::assertStringNotContainsString('<', $a['text'] . $a['title'] . $j['text']);
        self::assertStringNotContainsString('](http', $a['text']);
        self::assertSame('#d00000', $a['color']);
        self::assertLessThanOrEqual(10, count($a['fields']));
    }

    public function testTeamsAdaptiveCard(): void
    {
        $j = json_decode(PayloadFormatter::format('teams', self::event('ticket.created', self::hostile()))->body, true);
        self::assertSame('message', $j['type']);
        $card = $j['attachments'][0]['content'];
        self::assertSame('AdaptiveCard', $card['type']);
        foreach ($card['body'] as $b) {
            $texts = isset($b['facts']) ? array_merge(array_column($b['facts'], 'title'), array_column($b['facts'], 'value')) : [$b['text']];
            foreach ($texts as $t) {
                self::assertStringNotContainsString('<', $t);
                self::assertDoesNotMatchRegularExpression('/(?<!\\\\)\[x\]\(/', $t);
            }
        }
        self::assertLessThan(28000, strlen(json_encode($j)));
    }

    public function testDiscordLimits(): void
    {
        $ev = self::hostile();
        $ev['extra1'] = str_repeat('x', 3000);
        $j = json_decode(PayloadFormatter::format('discord', self::event('custom.thing', $ev), ['content' => str_repeat('c', 5000), 'username' => 'Bot'])->body, true);
        self::assertLessThanOrEqual(2000, mb_strlen($j['content']));
        self::assertSame(['parse' => []], $j['allowed_mentions']);
        $e = $j['embeds'][0];
        self::assertLessThanOrEqual(256, mb_strlen($e['title']));
        self::assertLessThanOrEqual(4096, mb_strlen($e['description']));
        self::assertLessThanOrEqual(25, count($e['fields'] ?? []));
        $total = mb_strlen($e['title']) + mb_strlen($e['description']) + mb_strlen($e['footer']['text']);
        foreach ($e['fields'] ?? [] as $f) {
            self::assertLessThanOrEqual(256, mb_strlen($f['name']));
            self::assertLessThanOrEqual(1024, mb_strlen($f['value']));
            $total += mb_strlen($f['name']) + mb_strlen($f['value']);
        }
        self::assertLessThanOrEqual(6000, $total);
        self::assertSame(self::TS, $e['timestamp']);
        self::assertStringNotContainsString('<script', $e['description']);
        self::assertArrayNotHasKey('url', $e, 'unsafe url dropped');
    }

    public function testNtfyHeadersAndBody(): void
    {
        $p = PayloadFormatter::format('ntfy', self::event('ticket.created', self::hostile() + ['ticket_url' => 'https://x.example/t/1']), ['ntfy_tags' => 'a b,c<d>,e', 'link_url' => 'https://helpdesk.example.com/t/9']);
        self::assertSame('text/plain; charset=utf-8', $p->contentType);
        self::assertLessThanOrEqual(4096, strlen($p->body));
        self::assertSame('5', $p->headers['Priority']);
        self::assertSame('rotating_light,ab,cd,e', $p->headers['Tags']);
        self::assertSame('https://helpdesk.example.com/t/9', $p->headers['Click']);
        self::assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $p->headers['Title']);
        self::assertSame('4', PayloadFormatter::format('ntfy', self::event('ticket.created'), ['ntfy_priority' => 4])->headers['Priority']);
    }

    public function testNtfyEncodesNonAsciiTitleAsEncodedWord(): void
    {
        $p = PayloadFormatter::format('ntfy', self::event('ticket.created', ['ticket_number' => '1', 'ticket_subject' => 'x']), ['test' => true, 'app_name' => 'Rivét']);
        self::assertStringStartsWith('=?UTF-8?B?', $p->headers['Title']);
        self::assertSame('TEST MESSAGE from Rivét', base64_decode(substr($p->headers['Title'], 10, -2)));
    }

    public function testGotifyPriorityAndClickExtra(): void
    {
        $j = json_decode(PayloadFormatter::format('gotify', self::event('ticket.created', ['ticket_subject' => 'x', 'ticket_priority' => 'High', 'url' => 'https://h.example/t/1']))->body, true);
        self::assertSame(6, $j['priority']);
        self::assertSame('https://h.example/t/1', $j['extras']['client::notification']['click']['url']);
        $j = json_decode(PayloadFormatter::format('gotify', self::event('ticket.created'), ['gotify_priority' => 99])->body, true);
        self::assertSame(10, $j['priority']);
    }

    public function testTelegramHtmlEscapingAndLimit(): void
    {
        $j = json_decode(PayloadFormatter::format('telegram', self::event('ticket.created', self::hostile()), ['chat_id' => '-1001234567890'])->body, true);
        self::assertSame(-1001234567890, $j['chat_id']);
        self::assertSame('HTML', $j['parse_mode']);
        self::assertLessThanOrEqual(4096, mb_strlen($j['text']));
        preg_match_all('#</?([a-z]+)#i', $j['text'], $m);
        self::assertSame([], array_diff(array_unique($m[1]), ['b', 'a']));
        self::assertStringContainsString('&lt;script&gt;', $j['text']);
        $c = json_decode(PayloadFormatter::format('telegram', self::event('ticket.created'), ['chat_id' => '@mychannel'])->body, true);
        self::assertSame('@mychannel', $c['chat_id']);
    }

    #[DataProvider('badChatIds')]
    public function testTelegramRequiresValidChatId(string $chat): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayloadFormatter::format('telegram', self::event('ticket.created'), ['chat_id' => $chat]);
    }

    /** @return iterable<string,array{string}> */
    public static function badChatIds(): iterable
    {
        yield 'empty' => [''];
        yield 'injection' => ['1","x":"y'];
        yield 'text' => ['hello world'];
    }

    public function testMatrixFormats(): void
    {
        $c = json_decode(PayloadFormatter::format('matrix', self::event('ticket.created', self::hostile()))->body, true);
        self::assertSame('m.text', $c['msgtype']);
        self::assertSame('org.matrix.custom.html', $c['format']);
        self::assertStringNotContainsString('<script', $c['formatted_body']);
        self::assertStringContainsString('&lt;script&gt;', $c['formatted_body']);
        $h = json_decode(PayloadFormatter::format('matrix_hookshot', self::event('ticket.created'), ['username' => 'RivetIT'])->body, true);
        self::assertSame(['text', 'html', 'username'], array_keys($h));
    }

    public function testAppriseTypeMapping(): void
    {
        $info = json_decode(PayloadFormatter::format('apprise', self::event('ticket.created', ['ticket_subject' => 's', 'ticket_priority' => 'Low']), ['apprise_tag' => 'a,b<x>'])->body, true);
        self::assertSame('info', $info['type']);
        self::assertSame('a,bx', $info['tag']);
        self::assertSame('failure', json_decode(PayloadFormatter::format('apprise', self::event('backup.failed'))->body, true)['type']);
        self::assertSame('warning', json_decode(PayloadFormatter::format('apprise', self::event('auth.login_failed'))->body, true)['type']);
    }

    public function testFormEncodingFlattensAndRedacts(): void
    {
        $p = PayloadFormatter::format('form', self::event('ticket.created', ['ticket_id' => 5, 'client' => ['name' => 'A&B = C', 'tags' => ['x', 'y']], 'password' => 'S3CRET', 'ok' => true, 'n' => null, 'long' => str_repeat('z', 5000)]));
        self::assertSame('application/x-www-form-urlencoded', $p->contentType);
        $pairs = [];
        foreach (explode('&', $p->body) as $kv) {
            [$k, $v] = explode('=', $kv, 2) + [1 => ''];
            $pairs[rawurldecode($k)] = rawurldecode($v);
        }
        self::assertSame('ticket.created', $pairs['event']);
        self::assertSame('5', $pairs['data.ticket_id']);
        self::assertSame('A&B = C', $pairs['data.client.name']);
        self::assertSame('y', $pairs['data.client.tags.1']);
        self::assertSame('[redacted]', $pairs['data.password']);
        self::assertSame('true', $pairs['data.ok']);
        self::assertSame('', $pairs['data.n']);
        self::assertSame(2000, mb_strlen($pairs['data.long']));
        self::assertStringNotContainsString('S3CRET', $p->body);
    }

    public function testFormCapsKeyCountAndDepth(): void
    {
        $data = [];
        for ($i = 0; $i < 1000; $i++) {
            $data['k' . $i] = $i;
        }
        $p = PayloadFormatter::format('form', self::event('x.y', $data));
        self::assertLessThanOrEqual(200, substr_count($p->body, '='));
    }

    public function testTemplateFormatUsesContextAndRedactsSecrets(): void
    {
        $p = PayloadFormatter::format('template', self::event('x.y', ['password' => 'S3CRET', 'a' => 'b']), ['template' => 'pw={{data.password}} a={{data.a}} t={{summary.title|upper}}', 'template_encoding' => 'text']);
        self::assertSame('pw=[redacted] a=b t=X Y', $p->body);
        self::assertSame('text/plain; charset=utf-8', $p->contentType);
        $f = PayloadFormatter::format('template', self::event('x.y', ['a' => 'b c']), ['template' => 'a={{data.a}}', 'template_encoding' => 'form']);
        self::assertSame('a=b%20c', $f->body);
        self::assertSame('application/x-www-form-urlencoded', $f->contentType);
    }

    public function testTemplateFormatRequiresValidTemplate(): void
    {
        foreach ([[], ['template' => '{{ system("id") }}'], ['template' => '{{x']] as $o) {
            try {
                PayloadFormatter::format('template', self::event('x.y'), $o);
                self::fail('expected exception');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testUnknownFormatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayloadFormatter::format('carrier-pigeon', self::event('x.y'));
    }

    public function testTestOptionAndLinkOverride(): void
    {
        $j = json_decode(PayloadFormatter::format('slack', self::event('ticket.created'), ['test' => true, 'app_name' => 'RivetIT', 'link_url' => 'https://h.example/t/1', 'link_label' => 'Open ticket'])->body, true);
        self::assertSame('TEST MESSAGE from RivetIT', $j['blocks'][0]['text']['text']);
        self::assertSame('https://h.example/t/1', $j['blocks'][2]['elements'][0]['url']);
        self::assertSame('Open ticket', $j['blocks'][2]['elements'][0]['text']['text']);
        $bad = json_decode(PayloadFormatter::format('slack', self::event('ticket.created'), ['link_url' => 'javascript:alert(1)'])->body, true);
        self::assertCount(2, $bad['blocks']);
    }

    public function testEventSummaryFamiliesAndFallbacks(): void
    {
        $t = EventSummary::fromEvent('ticket.created', PayloadTemplate::sampleContext('ticket.created')['data']);
        self::assertSame('New ticket', $t['title']);
        self::assertSame('TCK-1042 Printer on floor 2 is offline', $t['summary']);
        self::assertSame('warning', $t['severity']);
        self::assertSame('Acme Corp', $t['client']);
        self::assertContains(['name' => 'Priority', 'value' => 'High'], $t['fields']);

        $u = EventSummary::fromEvent('widget.sprocket_jammed', ['machine' => 'M7', 'count' => 3, 'ok' => false, 'api_key' => 'k', 'nested' => ['x' => 1]]);
        self::assertSame('Widget sprocket jammed', $u['title']);
        $names = array_column($u['fields'], 'name');
        self::assertContains('Machine', $names);
        self::assertContains('Count', $names);
        self::assertNotContains('Api key', $names);
        self::assertNotContains('Nested', $names);
        self::assertSame('info', $u['severity']);

        self::assertSame('critical', EventSummary::fromEvent('backup.failed', [])['severity']);
        self::assertSame('Event', EventSummary::fromEvent('', [])['title']);
        self::assertLessThanOrEqual(EventSummary::MAX_FIELDS, count(EventSummary::fromEvent('x.y', array_fill_keys(array_map(static fn ($i) => 'k' . $i, range(1, 50)), 'v'))['fields']));
    }
}
