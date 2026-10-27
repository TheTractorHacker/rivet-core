<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Panel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Panel\PanelText;
use RivetCore\Rmm\Http\ApiError;

final class PanelTextTest extends TestCase
{
    /** @return array<string,array{string,string}> */
    public static function inputs(): array
    {
        return [
            'plain' => ['hello', 'hello'],
            'trim' => ["  hi \n", 'hi'],
            'crlf' => ["a\r\nb\rc", "a\nb\nc"],
            'nul' => ["a\x00b", 'ab'],
            'c0' => ["a\x07\x1b[31mb", 'a[31mb'],
            'c1' => ["a\u{0085}b\u{009F}", 'ab'],
            'rlo' => ["gpj.\u{202E}exe", 'gpj.exe'],
            'isolates' => ["a\u{2066}b\u{2069}c", 'abc'],
            'marks' => ["a\u{200E}b\u{200F}c\u{061C}", 'abc'],
            'zero width' => ["a\u{200B}b\u{200D}c\u{2060}d\u{FEFF}", 'abcd'],
            'separators' => ["a\u{2028}b\u{2029}c", 'abc'],
            'soft hyphen' => ["a\u{00AD}b", 'ab'],
            'markup kept on input' => ['<error> & more', '<error> & more'],
            'emoji kept' => ["ok \u{1F600}", "ok \u{1F600}"],
            'tab and newline kept' => ["a\tb\nc", "a\tb\nc"],
        ];
    }

    #[DataProvider('inputs')]
    public function testInputStripsInvisibleAndControlCharacters(string $in, string $out): void
    {
        $this->assertSame($out, PanelText::input($in, 'f', 100, true));
    }

    public function testSingleLineFieldsFoldNewlines(): void
    {
        $this->assertSame('a b c', PanelText::input("a\nb\t\tc", 'f', 100, false));
    }

    public function testInputRefusesWrongTypeBadUtf8EmptyAndTooLong(): void
    {
        foreach ([[5, 'f must be a string'], [null, 'must be a string'], [['a'], 'must be a string'], ["\xC3\x28", 'UTF-8'], ['', 'required'], ["\u{202E}\x00 ", 'required'], [str_repeat('é', 11), 'longer than 10']] as [$v, $msg]) {
            try {
                PanelText::input($v, 'f', 10, true);
                $this->fail('expected a refusal for ' . json_encode($v));
            } catch (ApiError $e) {
                $this->assertSame([422, 'invalid'], [$e->http, $e->errCode]);
                $this->assertStringContainsString(substr($msg, 0, 8), $e->getMessage());
            }
        }
        $this->assertSame('', PanelText::input('', 'f', 10, false, true));
        $this->assertSame(str_repeat('é', 10), PanelText::input(str_repeat('é', 10), 'f', 10, false), 'the limit counts characters, not bytes');
    }

    /** @return array<string,array{mixed,string}> */
    public static function outputs(): array
    {
        return [
            'script' => ['<script>alert(1)</script>hi', 'alert(1)hi'],
            'tags' => ['<b>bold</b> <a href="javascript:x">link</a>', 'bold link'],
            'img onerror' => ['<img src=x onerror=alert(1)>text', 'text'],
            'entity encoded script' => ['&lt;script&gt;alert(1)&lt;/script&gt;', 'alert(1)'],
            'double encoded' => ['&amp;lt;script&amp;gt;', '&lt;script&gt;'],
            'lone angle brackets' => ['a < b > c', 'a &lt; b &gt; c'],
            'unclosed tag' => ['text <script src=//evil', 'text'],
            'bidi' => ["file\u{202E}gnp.exe", 'filegnp.exe'],
            'null byte' => ["a\x00<b>", 'a'],
            'int' => [42, '42'],
            'null' => [null, ''],
            'array' => [['x'], ''],
            'invalid utf8 is repaired not echoed' => ["ok\xFF\xFE<b>", 'ok??'],
        ];
    }

    #[DataProvider('outputs')]
    public function testOutputNeutralisesMarkupAndBidi(mixed $in, string $out): void
    {
        $got = PanelText::output($in);
        $this->assertDoesNotMatchRegularExpression('/[<>]/', $got);
        if (!str_contains($out, '??')) {
            $this->assertSame($out, $got);
        } else {
            $this->assertTrue(mb_check_encoding($got, 'UTF-8'));
        }
    }

    public function testOutputKeepsNewlinesOnlyForMultilineFields(): void
    {
        $this->assertSame("a\nb", PanelText::output("a\r\nb"));
        $this->assertSame('a b', PanelText::output("a\r\nb", false));
    }
}
