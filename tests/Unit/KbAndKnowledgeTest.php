<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\KB\DocxConversionException;
use RivetCore\KB\DocxConverter;
use RivetCore\KB\PdfConversionException;
use RivetCore\KB\PdfConverter;
use RivetCore\Knowledge\CredentialReferenceRenderer;
use RivetCore\Tests\Support\Fixtures;

final class KbAndKnowledgeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rc-kb-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testDocxConvertsHeadingsInlineFormattingAndTables(): void
    {
        Fixtures::docx($this->dir . '/a.docx');
        $r = DocxConverter::convert($this->dir . '/a.docx');
        $html = json_encode($r);
        $this->assertStringContainsString('Reset a password', $html);
        $this->assertMatchesRegularExpression('/<h[1-3]/', $r['html'] ?? (string) reset($r));
        $this->assertStringContainsString('<strong>click Forgot</strong>', $r['html'] ?? (string) reset($r));
        $this->assertStringContainsString('<table', $r['html'] ?? (string) reset($r));
    }

    public function testDocxRejectsNonDocxAndEmptyFiles(): void
    {
        file_put_contents($this->dir . '/notzip.docx', 'plain text');
        $this->expectException(DocxConversionException::class);
        DocxConverter::convert($this->dir . '/notzip.docx');
    }

    public function testDocxRejectsMissingFile(): void
    {
        $this->expectException(DocxConversionException::class);
        DocxConverter::convert($this->dir . '/nope.docx');
    }

    public function testPdfConvertsText(): void
    {
        if (!is_file('/usr/bin/pdftotext') && !trim((string) shell_exec('command -v pdftotext'))) {
            $this->markTestSkipped('poppler-utils not installed');
        }
        Fixtures::pdf($this->dir . '/a.pdf', 'Hello RivetCore PDF');
        $r = PdfConverter::convert($this->dir . '/a.pdf');
        $this->assertStringContainsString('Hello RivetCore PDF', json_encode($r));
    }

    public function testPdfRejectsGarbage(): void
    {
        file_put_contents($this->dir . '/bad.pdf', 'not a pdf');
        $this->expectException(PdfConversionException::class);
        PdfConverter::convert($this->dir . '/bad.pdf');
    }

    public function testCredentialReferencesAreSwappedForTheEditionsBadge(): void
    {
        $r = new CredentialReferenceRenderer(static fn (int $id): string => "<a data-id=\"$id\">reveal</a>");
        $html = '<p>Login: [[credential:12]] and [[Credential:7]]; leave [[credential:abc]] alone</p>';
        $this->assertTrue($r->containsReference($html));
        $out = $r->render($html);
        $this->assertStringContainsString('<a data-id="12">reveal</a>', $out);
        $this->assertStringContainsString('<a data-id="7">reveal</a>', $out);
        $this->assertStringContainsString('[[credential:abc]]', $out);
        $this->assertFalse($r->containsReference('<p>none</p>'));
        $this->assertSame('<p>none</p>', $r->render('<p>none</p>'));
    }
}
