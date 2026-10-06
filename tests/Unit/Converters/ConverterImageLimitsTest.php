<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Converters;

use RivetCore\KB\DocxConversionException;
use RivetCore\KB\DocxConverter;

/** Security review 2026-10 (SR-10): an image that declares absurd dimensions is skipped, whatever its byte size. */
final class ConverterImageLimitsTest extends ConverterTestCase
{
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function drawing(string $id): string
    {
        return '<w:p><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:blipFill>'
            . '<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . $id . '"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    private function withDimensions(int $w, int $h): string
    {
        $png = base64_decode(self::ONE_PIXEL_PNG);

        // IHDR width/height live at bytes 16..23; the CRC is not checked by getimagesizefromstring()
        return substr($png, 0, 16) . pack('N', $w) . pack('N', $h) . substr($png, 24);
    }

    public function testHugeDeclaredDimensionsAreSkippedButNormalImagesStay(): void
    {
        $rels = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/ok.png"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/huge.png"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/wide.png"/></Relationships>';
        $r = DocxConverter::convert($this->docx([
            'word/document.xml' => $this->docXml($this->para('t') . $this->drawing('rId1') . $this->drawing('rId2') . $this->drawing('rId3')),
            'word/_rels/document.xml.rels' => $rels,
            'word/media/ok.png' => $this->withDimensions(3000, 2000),
            'word/media/huge.png' => $this->withDimensions(60000, 60000),
            'word/media/wide.png' => $this->withDimensions(40000, 1),
        ]));
        self::assertCount(1, $r['media']);
        self::assertStringContainsString('extreme dimensions', implode(' ', $r['warnings']));
    }

    public function testUtf16EncodedDoctypeIsStillRejected(): void
    {
        // a byte scan for "<!DOCTYPE" misses UTF-16; the parsed-document check must catch it
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE d [<!ENTITY x "boom">]>'
            . '<w:document xmlns:w="' . self::W_NS . '"><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>';
        $this->expectException(DocxConversionException::class);
        $this->expectExceptionMessage('document type declaration');
        DocxConverter::convert($this->docx(['word/document.xml' => "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8')]));
    }
}
