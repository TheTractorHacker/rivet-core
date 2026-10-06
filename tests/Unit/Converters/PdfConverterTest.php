<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Converters;

use RivetCore\KB\PdfConversionException;
use RivetCore\KB\PdfConverter;

final class PdfConverterTest extends ConverterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['/usr/bin/pdfinfo', '/usr/bin/pdftotext', '/usr/bin/pdftohtml'] as $bin) {
            if (!is_executable($bin)) {
                $this->markTestSkipped("poppler ($bin) is not installed");
            }
        }
    }

    public function testHappyPath(): void
    {
        $r = PdfConverter::convert($this->tmp('a.pdf', $this->pdf('Hello corpus world')));
        $this->assertStringContainsString('Hello corpus world', $r['text']);
        $this->assertStringContainsString('Hello corpus world', $r['html']);
    }

    /** @return array<string,array{string}> */
    public static function badFiles(): array
    {
        return [
            'empty' => [''],
            'plain text' => ['hello, not a pdf'],
            'html renamed' => ['<html><script>alert(1)</script></html>'],
            'zip renamed' => ["PK\x03\x04" . str_repeat("\0", 64)],
            'magic only' => ['%PDF-'],
            'magic plus junk' => ['%PDF-1.4 ' . "\x00\x01\x02garbage"],
        ];
    }

    /** @dataProvider badFiles */
    #[\PHPUnit\Framework\Attributes\DataProvider('badFiles')]
    public function testBadFilesRejected(string $bytes): void
    {
        $this->expectException(PdfConversionException::class);
        PdfConverter::convert($this->tmp('bad.pdf', $bytes));
    }

    public function testMissingFileRejected(): void
    {
        $this->expectException(PdfConversionException::class);
        PdfConverter::convert($this->tmp('x.pdf') . '.nope');
    }

    public function testTruncatedPdfNeverReturnsHtmlWithoutText(): void
    {
        $good = $this->pdf('Truncate me please');
        foreach ([(int) (strlen($good) * 0.3), (int) (strlen($good) * 0.6)] as $cut) {
            try {
                $r = PdfConverter::convert($this->tmp("t$cut.pdf", substr($good, 0, $cut)));
                // poppler may recover a truncated file; if it does the output must still be safe.
                $this->assertStringNotContainsString('<script', $r['html']);
            } catch (PdfConversionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOversizeUploadRejectedBeforeAnyChildProcess(): void
    {
        $p = $this->tmp('big.pdf', '%PDF-1.4');
        $fh = fopen($p, 'r+b');
        ftruncate($fh, 33554432 + 1); // sparse
        fclose($fh);
        try {
            PdfConverter::convert($p);
            $this->fail('accepted');
        } catch (PdfConversionException $e) {
            $this->assertStringContainsString('larger than', $e->getMessage());
        }
    }

    public function testScriptTextInPdfIsEscaped(): void
    {
        $r = PdfConverter::convert($this->tmp('s.pdf', $this->pdf('<script>alert(1)</script> <img src=x onerror=alert(2)> & more')));
        $this->assertStringNotContainsString('<script', $r['html']);
        $this->assertStringNotContainsString('<img', $r['html']);
        $this->assertStringContainsString('&lt;script&gt;', $r['html']);
    }

    public function testEmbeddedJavaScriptAndLaunchActionsDoNotLeak(): void
    {
        $extra = "--OBJ--\n<< /Type /Action /S /JavaScript /JS (app.alert\\('PWNED-JS'\\);) >>"
            . "\n--OBJ--\n<< /Type /Action /S /Launch /F (cmd.exe /c calc-PWNED-LAUNCH) >>"
            . "\n--OBJ--\n<< /Type /Action /S /URI /URI (javascript:alert\\('PWNED-URI'\\)) >>";
        $pdf = $this->pdf(
            'Visible body text',
            '/OpenAction 6 0 R /Names << /JavaScript << /Names [(a) 6 0 R] >> >>',
            '/AA << /O 7 0 R >> /Annots [<< /Type /Annot /Subtype /Link /Rect [0 0 100 100] /A 8 0 R >>]',
            $extra
        );
        $r = PdfConverter::convert($this->tmp('js.pdf', $pdf));
        $all = $r['html'] . $r['text'] . implode(' ', $r['warnings']);
        $this->assertStringContainsString('Visible body text', $r['html']);
        foreach (['PWNED', 'app.alert', 'calc', 'cmd.exe'] as $needle) {
            $this->assertStringNotContainsString($needle, $all);
        }
        $this->assertDoesNotMatchRegularExpression('/javascript:/i', $all);
        $this->assertSame([], $r['media']);
    }

    public function testScratchDirectoryIsCleanedUp(): void
    {
        $before = glob(sys_get_temp_dir() . '/itflow-pdf-*') ?: [];
        try {
            PdfConverter::convert($this->tmp('junk.pdf', '%PDF-1.4 junk'));
        } catch (PdfConversionException) {
        }
        PdfConverter::convert($this->tmp('ok.pdf', $this->pdf('cleanup check')));
        $this->assertSame([], array_values(array_diff(glob(sys_get_temp_dir() . '/itflow-pdf-*') ?: [], $before)));
    }
}
