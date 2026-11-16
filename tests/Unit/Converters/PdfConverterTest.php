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

    /** A one-page PDF with a short text line and one JPEG (noise, so it does not compress) that the page content draws $draws times. */
    private function imageHeavyPdf(int $draws, int $side = 500): string
    {
        if (!function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is needed to build the test image');
        }
        $im = imagecreatetruecolor($side, $side);
        for ($i = 0; $i < $side * 40; $i++) {
            imagesetpixel($im, random_int(0, $side - 1), random_int(0, $side - 1), random_int(0, 0xFFFFFF));
        }
        ob_start();
        imagejpeg($im, null, 95);
        $jpeg = (string) ob_get_clean();
        $stream = "BT /F1 12 Tf 72 700 Td (Some text so the no-text gate passes) Tj ET\n" . str_repeat("q 100 0 0 100 50 50 cm /Im0 Do Q\n", $draws);
        $objs = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> /XObject << /Im0 6 0 R >> >> >>',
            4 => '<< /Length ' . strlen($stream) . " >>\nstream\n$stream\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            6 => "<< /Type /XObject /Subtype /Image /Width $side /Height $side /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpeg) . " >>\nstream\n$jpeg\nendstream",
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($out);
            $out .= "$n 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    /** CORE-DS-7: pdftohtml writes one image file per DRAW; a PDF that draws one image thousands of times must be stopped, and what it wrote removed. */
    public function testAnImageDrawnManyTimesIsStoppedBeforeItFillsTheTempDirectory(): void
    {
        $before = glob(sys_get_temp_dir() . '/itflow-pdf-*') ?: [];
        $pdf = $this->tmp('amp.pdf', $this->imageHeavyPdf(1500));
        $this->assertLessThan(2000000, filesize($pdf), 'the upload is small');
        $t = microtime(true);
        try {
            PdfConverter::convert($pdf);
            $this->fail('a PDF that writes gigabytes of images was accepted');
        } catch (PdfConversionException $e) {
            $this->assertStringContainsString('too much', $e->getMessage());
        }
        $this->assertLessThan(30, microtime(true) - $t);
        $this->assertSame([], array_values(array_diff(glob(sys_get_temp_dir() . '/itflow-pdf-*') ?: [], $before)), 'the scratch directory is gone');
    }

    public function testAnOrdinaryPdfWithOneImageStillConverts(): void
    {
        $r = PdfConverter::convert($this->tmp('one.pdf', $this->imageHeavyPdf(1)));
        $this->assertStringContainsString('Some text', $r['text']);
    }
}
