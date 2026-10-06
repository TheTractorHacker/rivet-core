# KB converters

`RivetCore\KB\DocxConverter` and `PdfConverter` turn an uploaded file into knowledge-base article HTML (plus plain text and image
bytes for PDF). They never write to the web root and never touch the database: images come back as bytes with an opaque token the
caller substitutes. The input is hostile until proven otherwise; both classes document their threat model in their docblocks.

## What it owns

No tables. DOCX needs `ext-zip` and `ext-dom`; PDF needs poppler (`pdfinfo`, `pdftohtml`, `pdftotext`, `pdfimages` from `poppler-utils`)
on the host.

## You supply

A path to the uploaded file (already size-checked by the edition) and the place to store the returned media.

## Flags

None. The edition decides who may import.

## Use it

<!-- run -->
```php
use RivetCore\KB\DocxConverter;

$path = sys_get_temp_dir() . '/rc-doc-sample.docx';
$zip = new ZipArchive();
$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
$zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Reset a password</w:t></w:r></w:p></w:body></w:document>');
$zip->close();

$result = DocxConverter::convert($path);
unlink($path);
echo strip_tags($result['html']), "\n";
```

## How it fails

Both throw `DocxConversionException` / `PdfConversionException` with a user-presentable message on bad input and never return partial
output for a rejected file. Limits asserted by the converter corpus (tests/Unit/Converters):

| | DOCX | PDF |
|---|---|---|
| Input size | declared total uncompressed 48 MiB, at most 1024 entries, compression ratio at most 500 | upload 32 MiB, at most 200 pages |
| Parts | `document.xml` 8 MiB, relations and numbering 4 MiB each, nesting depth 24 | text 1 MiB, lines 20 000 characters |
| Media | 100 images, 8 MiB each, 24 MiB in total | 24 MiB of images in total |
| Output | HTML truncated at 4 MiB with a warning | |

A DOCX carrying a DOCTYPE is rejected (XXE and entity expansion); entries are read by name into memory, never extracted, so
traversal entry names have nothing to hit. PDF is read by poppler subprocesses with fixed argument vectors (never through a shell) and
fixed binary paths (`/usr/bin/pdfinfo`, `pdftotext`, `pdftohtml`, `pdfimages`: a poppler installed elsewhere is not found). The
converters are excluded from the 85% coverage gate and carry a PHPStan baseline.
