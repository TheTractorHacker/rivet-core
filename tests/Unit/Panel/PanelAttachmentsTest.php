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
        ];
    }

    #[DataProvider('refused')]
    public function testRefuses(string $name, string $type, string $bytes): void
    {
        $this->rejected([self::att($name, $type, $bytes)]);
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
}
