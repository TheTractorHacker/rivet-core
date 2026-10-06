<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Converters;

use RivetCore\KB\DocxConversionException;
use RivetCore\KB\DocxConverter;

final class DocxConverterTest extends ConverterTestCase
{
    public function testHappyPathConvertsHeadingParagraphAndList(): void
    {
        $body = '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Title</w:t></w:r></w:p>'
            . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Bold</w:t></w:r><w:r><w:t> plain</w:t></w:r></w:p>';
        $r = DocxConverter::convert($this->docx(['word/document.xml' => $this->docXml($body)]));
        $this->assertStringContainsString('Title', $r['html']);
        $this->assertStringContainsString('<strong>Bold</strong>', $r['html']);
        $this->assertSame([], $r['media']);
    }

    public function testMissingOrUnreadableFileRejected(): void
    {
        $this->expectException(DocxConversionException::class);
        DocxConverter::convert($this->tmp('none.docx') . '.missing');
    }

    public function testEmptyFileRejected(): void
    {
        $this->expectException(DocxConversionException::class);
        DocxConverter::convert($this->tmp('e.docx', ''));
    }

    public function testCorruptAndTruncatedZipRejected(): void
    {
        $good = file_get_contents($this->docx(['word/document.xml' => $this->docXml($this->para('hi'))]));
        foreach ([substr((string) $good, 0, (int) (strlen((string) $good) / 2)), random_bytes(300), "PK\x03\x04garbage"] as $i => $bytes) {
            try {
                DocxConverter::convert($this->tmp("c$i.docx", $bytes));
                $this->fail("variant $i accepted");
            } catch (DocxConversionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testWrongMagicAndPlainTextRejected(): void
    {
        foreach (["%PDF-1.4 not a zip", '<html><script>alert(1)</script></html>', 'just text'] as $i => $bytes) {
            try {
                DocxConverter::convert($this->tmp("w$i.docx", $bytes));
                $this->fail("variant $i accepted");
            } catch (DocxConversionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testZipWithoutDocumentXmlRejected(): void
    {
        $this->expectException(DocxConversionException::class);
        DocxConverter::convert($this->docx(['readme.txt' => 'x']));
    }

    public function testEmptyBodyRejected(): void
    {
        $this->expectException(DocxConversionException::class);
        DocxConverter::convert($this->docx(['word/document.xml' => $this->docXml('')]));
    }

    public function testMalformedXmlRejected(): void
    {
        $this->expectException(DocxConversionException::class);
        DocxConverter::convert($this->docx(['word/document.xml' => '<w:document><w:body><w:p>']));
    }

    public function testZipBombByDeclaredTotalRejected(): void
    {
        // 60 MiB of zeros deflates to ~60 KB; the declared size exceeds the 48 MiB budget.
        $path = $this->docx([
            'word/document.xml' => $this->docXml($this->para('x')),
            'word/media/big.bin' => str_repeat("\0", 60 * 1048576),
        ]);
        $this->assertLessThan(5 * 1048576, filesize($path));
        $this->expectException(DocxConversionException::class);
        DocxConverter::convert($path);
    }

    public function testHighCompressionRatioEntryRejected(): void
    {
        // 30 MiB of zeros: under the total cap, over the 500:1 ratio on a >=4 MiB entry.
        $path = $this->docx([
            'word/document.xml' => $this->docXml($this->para('x')),
            'word/media/bomb.bin' => str_repeat("\0", 30 * 1048576),
        ]);
        try {
            DocxConverter::convert($path);
            $this->fail('bomb accepted');
        } catch (DocxConversionException $e) {
            $this->assertStringContainsString('decompression bomb', $e->getMessage());
        }
    }

    public function testOversizeDocumentXmlRejected(): void
    {
        $big = $this->docXml(str_repeat($this->para('lorem ipsum dolor ' . random_int(0, 9)), 1) . '<!--' . str_repeat('a', 9 * 1048576) . '-->');
        $this->expectException(DocxConversionException::class);
        DocxConverter::convert($this->docx(['word/document.xml' => $big]));
    }

    public function testTooManyEntriesRejected(): void
    {
        $entries = ['word/document.xml' => $this->docXml($this->para('x'))];
        for ($i = 0; $i < 1100; $i++) {
            $entries["word/media/f$i.txt"] = 'a';
        }
        try {
            DocxConverter::convert($this->docx($entries));
            $this->fail('accepted');
        } catch (DocxConversionException $e) {
            $this->assertStringContainsString('too many', $e->getMessage());
        }
    }

    public function testPathTraversalEntriesAreInertAndNeverWritten(): void
    {
        $marker = sys_get_temp_dir() . '/rc-pwned-' . bin2hex(random_bytes(4));
        $path = $this->docx([
            'word/document.xml' => $this->docXml($this->para('safe')),
            '../../../../' . ltrim($marker, '/') => 'owned',
            '/etc/rc-evil' => 'owned',
            'word/../../evil.php' => '<?php echo 1;',
        ]);
        $r = DocxConverter::convert($path);
        $this->assertStringContainsString('safe', $r['html']);
        $this->assertFileDoesNotExist($marker);
        $this->assertFileDoesNotExist('/etc/rc-evil');
        $this->assertStringNotContainsString('owned', $r['html']);
    }

    public function testTraversalRelationshipTargetNotFollowed(): void
    {
        $rels = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../../secret.png"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="/etc/passwd"/>'
            . '</Relationships>';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $draw = static fn (string $id): string => '<w:p><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:blipFill>'
            . '<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . $id . '"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
        $r = DocxConverter::convert($this->docx([
            'word/document.xml' => $this->docXml($this->para('t') . $draw('rId1') . $draw('rId2')),
            'word/_rels/document.xml.rels' => $rels,
            'secret.png' => $png,
        ]));
        $this->assertSame([], $r['media']);
        $this->assertStringNotContainsString('<img', $r['html']);
    }

    public function testRealImageIsSniffedAndTokenised_NonImageMediaDropped(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $rels = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/evil.png"/></Relationships>';
        $draw = static fn (string $id): string => '<w:p><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:blipFill>'
            . '<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . $id . '"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
        $r = DocxConverter::convert($this->docx([
            'word/document.xml' => $this->docXml($this->para('t') . $draw('rId1') . $draw('rId2')),
            'word/_rels/document.xml.rels' => $rels,
            'word/media/image1.png' => $png,
            'word/media/evil.png' => '<?php system($_GET["c"]); ?><script>alert(1)</script>',
        ]));
        $this->assertCount(1, $r['media']);
        $this->assertSame('png', $r['media'][0]['extension']);
        $this->assertSame($png, $r['media'][0]['bytes']);
        $this->assertStringContainsString('<img src="' . $r['media'][0]['token'] . '"', $r['html']);
        $this->assertNotEmpty($r['warnings']);
    }

    public function testXxeExternalEntityRejected(): void
    {
        $secret = $this->tmp('secret.txt', 'TOP-SECRET-CONTENT');
        $xml = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file://' . $secret . '">]>'
            . '<w:document xmlns:w="' . self::W_NS . '"><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>';
        try {
            $r = DocxConverter::convert($this->docx(['word/document.xml' => $xml]));
            $this->assertStringNotContainsString('TOP-SECRET', $r['html']);
        } catch (DocxConversionException $e) {
            $this->assertStringNotContainsString('TOP-SECRET', $e->getMessage());
        }
        $this->expectException(DocxConversionException::class);
        DocxConverter::convert($this->docx(['word/document.xml' => $xml], 'b.docx'));
    }

    public function testXxeParameterEntityAndUtf16DoctypeRejected(): void
    {
        $secret = $this->tmp('s2.txt', 'TOP-SECRET-CONTENT');
        $plain = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE d [<!ENTITY x SYSTEM "file://' . $secret . '">]>'
            . '<w:document xmlns:w="' . self::W_NS . '"><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>';
        $utf16 = "\xFF\xFE" . mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8');
        try {
            $r = DocxConverter::convert($this->docx(['word/document.xml' => $utf16]));
            $this->assertStringNotContainsString('TOP-SECRET', $r['html']);
        } catch (DocxConversionException $e) {
            $this->assertStringNotContainsString('TOP-SECRET', $e->getMessage());
        }
        $this->addToAssertionCount(1);
    }

    public function testBillionLaughsRejected(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE l [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">]>'
            . '<w:document xmlns:w="' . self::W_NS . '"><w:body><w:p><w:r><w:t>&c;</w:t></w:r></w:p></w:body></w:document>';
        $this->expectException(DocxConversionException::class);
        DocxConverter::convert($this->docx(['word/document.xml' => $xml]));
    }

    public function testScriptAndHtmlInTextIsEscaped(): void
    {
        $evil = '<script>alert(1)</script><img src=x onerror=alert(2)> "q" \'s\' & done';
        $r = DocxConverter::convert($this->docx(['word/document.xml' => $this->docXml($this->para($evil))]));
        $this->assertStringNotContainsString('<script', $r['html']);
        $this->assertStringNotContainsString('<img', $r['html']);
        $this->assertStringContainsString('&lt;script&gt;', $r['html']);
    }

    public function testDangerousHyperlinkSchemesAreNotEmitted(): void
    {
        $rels = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="javascript:alert(1)" TargetMode="External"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="data:text/html;base64,PHNjcmlwdD4=" TargetMode="External"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.com/&quot; onclick=&quot;x" TargetMode="External"/>'
            . '</Relationships>';
        $link = static fn (string $id, string $t): string => '<w:p><w:hyperlink r:id="' . $id . '"><w:r><w:t>' . $t . '</w:t></w:r></w:hyperlink></w:p>';
        $r = DocxConverter::convert($this->docx([
            'word/document.xml' => $this->docXml($link('rId1', 'one') . $link('rId2', 'two') . $link('rId3', 'three')),
            'word/_rels/document.xml.rels' => $rels,
        ]));
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $r['html']);
        $this->assertStringNotContainsStringIgnoringCase('data:text', $r['html']);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $r['html']);
        foreach ($dom->getElementsByTagName('a') as $a) {
            $this->assertFalse($a->hasAttribute('onclick'));
            $this->assertMatchesRegularExpression('#^(https?:|mailto:)#i', $a->getAttribute('href'));
        }
    }

    public function testDeepNestingDoesNotCrash(): void
    {
        $inner = $this->para('deep');
        for ($i = 0; $i < 400; $i++) {
            $inner = '<w:tbl><w:tr><w:tc>' . $inner . '</w:tc></w:tr></w:tbl>';
        }
        try {
            $r = DocxConverter::convert($this->docx(['word/document.xml' => $this->docXml($this->para('top') . $inner)]));
            $this->assertStringContainsString('top', $r['html']);
        } catch (DocxConversionException) {
            $this->addToAssertionCount(1);
        }
    }
}
