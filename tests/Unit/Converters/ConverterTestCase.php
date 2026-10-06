<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Converters;

use PHPUnit\Framework\TestCase;

/** Builds throwaway DOCX/PDF fixtures in a temp dir; nothing is committed to tests/Fixtures. */
abstract class ConverterTestCase extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rc-conv-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    protected function tmp(string $name, string $bytes = ''): string
    {
        $p = $this->dir . '/' . $name;
        file_put_contents($p, $bytes);

        return $p;
    }

    protected const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    protected function docXml(string $bodyInner): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="' . self::W_NS . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body>' . $bodyInner . '</w:body></w:document>';
    }

    protected function para(string $text): string
    {
        return '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1) . '</w:t></w:r></w:p>';
    }

    /**
     * @param array<string,string> $entries zip name => bytes (document.xml defaults to one paragraph)
     */
    protected function docx(array $entries, string $name = 'a.docx'): string
    {
        $path = $this->dir . '/' . $name;
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $n => $bytes) {
            $zip->addFromString($n, $bytes);
        }
        $zip->close();

        return $path;
    }

    /** A minimal one-page PDF with the given Helvetica text and optional extra catalog / page entries. */
    protected function pdf(string $text, string $catalogExtra = '', string $pageExtra = '', string $extraObjects = ''): string
    {
        $stream = "BT /F1 12 Tf 72 700 Td (" . addcslashes($text, '()\\') . ") Tj ET";
        $objs = [
            1 => "<< /Type /Catalog /Pages 2 0 R $catalogExtra >>",
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> $pageExtra >>",
            4 => "<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $n = 5;
        foreach (array_filter(explode("\n--OBJ--\n", $extraObjects)) as $body) {
            $objs[++$n] = $body;
        }
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $body) {
            $offsets[$i] = strlen($out);
            $out .= "$i 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }
        $out .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";

        return $out;
    }
}
