<?php

declare(strict_types=1);

namespace RivetCore\Tests\Security;

use RivetCore\KB\DocxConversionException;
use RivetCore\KB\DocxConverter;
use RivetCore\KB\PdfConverter;

/** KB converter surface: RC-SR2-17, -18 plus guards for controls verified in the review. */
final class KbConverterSurfaceTest extends SecurityTestCase
{
    /** A valid PNG header and IDAT for an all-black 8-bit grey image: tiny on disk, huge once decoded. */
    private static function blackPng(int $w, int $h): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $z = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $row = "\0" . str_repeat("\0", $w); // filter byte + pixels
        $idat = '';
        for ($i = 0; $i < $h; $i++) {
            $idat .= deflate_add($z, $row, ZLIB_NO_FLUSH);
        }
        $idat .= deflate_add($z, '', ZLIB_FINISH);

        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0)) . $chunk('IDAT', $idat) . $chunk('IEND', '');
    }

    private function docxWith(string $path, array $entries): void
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
    }

    private function convertDocxWithImage(string $png): array
    {
        $path = $this->tmpFile('img.docx');
        $draw = '<w:p><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:blipFill>'
            . '<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId1"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
        $this->docxWith($path, [
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>x</w:t></w:r></w:p>' . $draw . '</w:body></w:document>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/></Relationships>',
            'word/media/image1.png' => $png,
        ]);
        try {
            return DocxConverter::convert($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Documents RC-SR2-17: PdfConverter runs poppler under a wall-clock timeout (verified below: a sleeping child is
     * SIGKILLed) but with NO CPU, address-space or file-size limit: the child inherits "unlimited" for ulimit -t/-v/-f.
     * Measured during the review: a 292 KB PDF holding one 10000x10000 flate image kept pdftohtml busy for 8.4 s
     * (peak RSS 20 MB), so one upload can occupy a CPU for up to the 45 s stage timeout, and nothing caps concurrent
     * imports. Flip: assert finite limits (prlimit / ulimit wrapper) for the child.
     */
    public function testRc17PopplerChildRunsWithoutResourceLimits(): void
    {
        $exec = new \ReflectionMethod(PdfConverter::class, 'exec');
        $converter = (new \ReflectionClass(PdfConverter::class))->newInstanceWithoutConstructor();
        $r = $exec->invoke($converter, ['/bin/sh', '-c', 'echo cpu=$(ulimit -t) vmem=$(ulimit -v) fsize=$(ulimit -f)'], sys_get_temp_dir(), 5000);
        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('cpu=unlimited vmem=unlimited fsize=unlimited', $r['stdout']);

        $t = microtime(true);
        $r = $exec->invoke($converter, ['/bin/sleep', '5'], sys_get_temp_dir(), 300);
        $this->assertTrue($r['timedout'], 'the wall-clock timeout does fire');
        $this->assertLessThan(2.0, microtime(true) - $t);
    }

    /**
     * Documents RC-SR2-18: neither converter bounds the pixel dimensions of an embedded image. Only byte size (8 MB each,
     * 24-25 MB total) and the image type are checked, so a ~25 KB PNG that decodes to 16000 x 16000 pixels (256 Mpx, 256
     * MB as 8-bit grey, 1 GB as RGBA) is accepted and handed to the caller. A caller that thumbnails or re-encodes media
     * with GD/Imagick can be driven out of memory, and browsers refuse such images. Flip: assert the image is skipped
     * with a warning once width*height exceeds a cap (for example 40 Mpx).
     */
    public function testRc18ImageWithHugePixelDimensionsIsAccepted(): void
    {
        $png = self::blackPng(16000, 16000);
        $this->assertLessThan(1048576, strlen($png), 'a small file...');
        $result = $this->convertDocxWithImage($png);
        $this->assertCount(1, $result['media']);
        $info = getimagesizefromstring($result['media'][0]['bytes']);
        $this->assertSame([16000, 16000], [$info[0], $info[1]], '...that decodes to 256 megapixels');
    }

    /** RC-SR2-18, PDF path: the same absence of a pixel cap, through pdftohtml. Skipped when poppler is missing. */
    public function testRc18PdfImageWithHugePixelDimensionsIsAccepted(): void
    {
        if (!is_executable('/usr/bin/pdftohtml') || !is_executable('/usr/bin/pdfinfo') || !is_executable('/usr/bin/pdftotext')) {
            $this->markTestSkipped('poppler-utils not installed');
        }
        [$w, $h] = [5000, 5000];
        $z = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $data = '';
        $row = str_repeat("\0", $w * 3);
        for ($i = 0; $i < $h; $i++) {
            $data .= deflate_add($z, $row, ZLIB_NO_FLUSH);
        }
        $data .= deflate_add($z, '', ZLIB_FINISH);
        $content = 'q 200 0 0 200 50 500 cm /Im1 Do Q BT /F1 12 Tf 50 450 Td (hello) Tj ET';
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> /XObject << /Im1 6 0 R >> >> >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n$content\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            "<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen($data) . " >>\nstream\n$data\nendstream",
        ];
        $out = "%PDF-1.4\n";
        $off = [];
        foreach ($objs as $i => $o) {
            $off[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n$o\nendobj\n";
        }
        $x = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($off as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }
        $out .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
        $path = $this->tmpFile('bomb.pdf');
        file_put_contents($path, $out);
        $this->assertLessThan(1048576, filesize($path));
        try {
            $r = PdfConverter::convert($path);
        } finally {
            @unlink($path);
        }
        $this->assertNotEmpty($r['media'], 'the 25-megapixel image was imported');
        $info = getimagesizefromstring($r['media'][0]['bytes']);
        $this->assertGreaterThanOrEqual(25000000, $info[0] * $info[1]);
    }

    /** Guard (no finding): XXE and entity tricks that survive the byte scan (UTF-16 encoded DOCTYPE) are still rejected after parsing. */
    public function testGuardUtf16DoctypeIsRejected(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE w:document [<!ENTITY a "x">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&a;</w:t></w:r></w:p></w:body></w:document>';
        $path = $this->tmpFile('u16.docx');
        $this->docxWith($path, ['word/document.xml' => "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8')]);
        try {
            $this->expectException(DocxConversionException::class);
            DocxConverter::convert($path);
        } finally {
            @unlink($path);
        }
    }

    /** Guard (no finding): zip-slip style media targets and a lying central directory are contained. */
    public function testGuardMediaTargetsCannotEscapeAndSizesAreEnforcedOnRealBytes(): void
    {
        $path = $this->tmpFile('slip.docx');
        $this->docxWith($path, [
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>x</w:t></w:r></w:p></w:body></w:document>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x/image" Target="../../etc/passwd"/><Relationship Id="rId2" Type="x/hyperlink" Target="javascript:alert(1)" TargetMode="External"/></Relationships>',
        ]);
        try {
            $r = DocxConverter::convert($path);
        } finally {
            @unlink($path);
        }
        $this->assertSame([], $r['media']);
        $this->assertStringNotContainsString('javascript:', $r['html']);
    }
}
