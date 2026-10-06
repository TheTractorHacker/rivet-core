<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Webhooks\PayloadTemplate;

final class PayloadTemplateTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function ctx(): array
    {
        return ['event' => 'ticket.created', 'data' => ['id' => 5, 'name' => '  Ünï "Q" \\ <b> ', 'tags' => ['a', 'b'], 'ok' => true, 'nil' => null, 'empty' => '', 'items' => [['n' => 'first']], 'long' => str_repeat('x', 100)]];
    }

    public function testPathsAndMissingValues(): void
    {
        self::assertSame('5|ticket.created|', PayloadTemplate::render('{{data.id}}|{{event}}|{{data.missing.deeper}}', self::ctx(), 'text'));
        self::assertSame('first', PayloadTemplate::render('{{data.items.0.n}}', self::ctx(), 'text'));
        self::assertSame('true|', PayloadTemplate::render('{{data.ok}}|{{data.nil}}', self::ctx(), 'text'));
        self::assertSame('{"a":"x"}', PayloadTemplate::render('{"a":"{{ data.nope|default:"x" }}"}', self::ctx(), 'json'));
    }

    public function testFilters(): void
    {
        self::assertSame('FIRST', PayloadTemplate::render('{{data.items.0.n|upper}}', self::ctx(), 'text'));
        self::assertSame('ÜNÏ "Q" \\ <B>', PayloadTemplate::render('{{data.name|trim|upper}}', self::ctx(), 'text'));
        self::assertSame('ünï "q" \\ <b>', PayloadTemplate::render('{{data.name|upper|lower|trim}}', self::ctx(), 'text'));
        self::assertSame('xxxx…', PayloadTemplate::render('{{data.long|truncate:5}}', self::ctx(), 'text'));
        self::assertSame('n/a', PayloadTemplate::render('{{data.empty|default:"n/a"}}', self::ctx(), 'text'));
        self::assertSame('a|b', PayloadTemplate::render('{{data.nil|default:"a|b"}}', self::ctx(), 'text'));
        self::assertSame('say "hi"', PayloadTemplate::render('{{data.nil|default:"say \"hi\""}}', self::ctx(), 'text'));
        self::assertSame('["a","b"]', PayloadTemplate::render('{{data.tags}}', self::ctx(), 'text'));
    }

    public function testJsonEncodingKeepsDocumentValid(): void
    {
        $out = PayloadTemplate::render('{"name":"{{data.name}}","id":{{data.id|json}},"tags":{{data.tags|json}},"ok":{{data.ok|json}},"none":{{data.nil|json}},"s":{{data.name|json}}}', self::ctx(), 'json');
        $j = json_decode($out, true);
        self::assertSame('  Ünï "Q" \\ <b> ', $j['name']);
        self::assertSame(5, $j['id']);
        self::assertSame(['a', 'b'], $j['tags']);
        self::assertTrue($j['ok']);
        self::assertNull($j['none']);
        self::assertSame('  Ünï "Q" \\ <b> ', $j['s']);
    }

    public function testJsonInjectionCannotBreakOut(): void
    {
        $ctx = ['data' => ['v' => '","admin":true,"x":"' . "\n\x00\u{2028}"]];
        $out = PayloadTemplate::render('{"v":"{{data.v}}"}', $ctx, 'json');
        $j = json_decode($out, true);
        self::assertSame(['v'], array_keys($j));
        self::assertSame($ctx['data']['v'], $j['v']);
    }

    public function testTextAndFormEncoding(): void
    {
        self::assertSame("a\nb", PayloadTemplate::render('{{data.v}}', ['data' => ['v' => "a\nb\0"]], 'text'));
        self::assertSame('x=a%26b%3Dc%20d%C3%A9&y=%22', PayloadTemplate::render('x={{data.v}}&y={{data.q}}', ['data' => ['v' => 'a&b=c dé', 'q' => '"']], 'form'));
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidProvider(): iterable
    {
        yield 'unclosed' => ['{"a":"{{data.id"}', 'json'];
        yield 'unclosed text' => ['hello {{ data.id', 'text'];
        yield 'unknown filter' => ['{{data.id|system}}', 'text'];
        yield 'function call' => ['{{ system("id") }}', 'text'];
        yield 'php var' => ['{{ $x }}', 'text'];
        yield 'backticks' => ['{{ `id` }}', 'text'];
        yield 'path traversal' => ['{{ ../../etc/passwd }}', 'text'];
        yield 'expression' => ['{{ 1+1 }}', 'text'];
        yield 'default unquoted' => ['{{data.id|default:x}}', 'text'];
        yield 'truncate nonnumeric' => ['{{data.id|truncate:abc}}', 'text'];
        yield 'truncate zero' => ['{{data.id|truncate:0}}', 'text'];
        yield 'json not last' => ['{{data.id|json|upper}}', 'json'];
        yield 'filter arg on upper' => ['{{data.id|upper:3}}', 'text'];
        yield 'empty placeholder' => ['{{}}', 'text'];
        yield 'invalid json result' => ['{"a": {{data.id}}x}', 'json'];
        yield 'unquoted string in json' => ['{"a": {{data.name}}}', 'json'];
        yield 'empty template' => ['  ', 'text'];
        yield 'bad encoding' => ['x', 'xml'];
        yield 'too large' => [str_repeat('a', 8193), 'text'];
        yield 'too many placeholders' => [str_repeat('{{data.id}}', 101), 'text'];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidTemplatesAreReported(string $template, string $encoding): void
    {
        $errors = PayloadTemplate::validate($template, $encoding);
        self::assertNotSame([], $errors);
        self::assertContainsOnlyString($errors);
    }

    #[DataProvider('invalidProvider')]
    public function testSyntacticallyInvalidTemplatesNeverRender(string $template, string $encoding): void
    {
        // These parse fine; they are only wrong as JSON/emptiness, which validate() catches after a sample render.
        if (in_array($template, ['{"a": {{data.id}}x}', '{"a": {{data.name}}}', '  '], true)) {
            self::addToAssertionCount(1);

            return;
        }
        $this->expectException(\InvalidArgumentException::class);
        PayloadTemplate::render($template, self::ctx(), $encoding);
    }

    public function testValidTemplatesValidate(): void
    {
        self::assertSame([], PayloadTemplate::validate('{"text":"{{summary.title}}: {{summary.summary|truncate:80}}","id":{{data.ticket_id|json}}}', 'json'));
        self::assertSame([], PayloadTemplate::validate('Ticket {{data.ticket_number|default:"?"}}', 'text'));
        self::assertSame([], PayloadTemplate::validate('a={{data.ticket_id}}&b={{summary.title}}', 'form'));
        self::assertSame([], PayloadTemplate::validate('{"nested":{"a":{"b":"{{event}}"}}}', 'json'), 'adjacent closing braces are fine');
    }

    public function testCodeLikeContentIsInertText(): void
    {
        $t = '<?php system("id"); ?> ${jndi:ldap://x} $(id) `id` %s {%raw%} {x} }}';
        self::assertSame($t, PayloadTemplate::render($t, [], 'text'));
        self::assertSame('<?php echo 1; ?>', PayloadTemplate::render('{{data.v}}', ['data' => ['v' => '<?php echo 1; ?>']], 'text'));
        // values are never re-interpreted as templates
        self::assertSame('{{data.id}}', PayloadTemplate::render('{{data.v}}', ['data' => ['v' => '{{data.id}}', 'id' => 9]], 'text'));
    }

    public function testRenderedSizeLimit(): void
    {
        $big = ['data' => ['v' => str_repeat('a', 40000)]];
        self::assertSame(40000, strlen(PayloadTemplate::render('{{data.v}}', $big, 'text')));
        $this->expectException(\InvalidArgumentException::class);
        PayloadTemplate::render('{{data.v}}{{data.v}}', $big, 'text');
    }

    public function testPlaceholdersListsDistinctPathsInOrder(): void
    {
        self::assertSame(['data.id', 'summary.title', 'event'], PayloadTemplate::placeholders('{{data.id}} {{ summary.title|upper }} {{data.id}} {{event}}'));
        self::assertSame([], PayloadTemplate::placeholders('no placeholders'));
    }

    public function testSampleContextIsRealistic(): void
    {
        $t = PayloadTemplate::sampleContext('ticket.created');
        self::assertSame('ticket.created', $t['event']);
        self::assertSame('TCK-1042', $t['data']['ticket_number']);
        self::assertSame('New ticket', $t['summary']['title']);
        $a = PayloadTemplate::sampleContext('backup.failed');
        self::assertSame('failed', $a['data']['action']);
        self::assertSame('critical', $a['summary']['severity']);
        foreach (['auth.login_failed', 'custom.unknown', 'sla.breached', 'x'] as $e) {
            self::assertNotSame([], PayloadTemplate::sampleContext($e)['data']);
        }
    }
}
