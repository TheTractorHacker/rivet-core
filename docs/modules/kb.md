# KB converters

Namespace `RivetCore\KB`. Two stateless converters that turn an uploaded Word or PDF file into knowledge-base article HTML: `DocxConverter` (native, no external program) and `PdfConverter` (poppler). Both treat the upload as hostile, share one contract, never write to disk outside a scratch directory, and never touch the database.

Media handling (writing images, rewriting URLs), HTML purification and the article editor stay in the editions (see [ADR-002](../architecture/ADR-002-modules-that-stay-in-editions.md)).

## Overview

- Owns no tables and has no migration.
- Classes: `DocxConverter`, `DocxConversionException`, `PdfConverter`, `PdfConversionException`. Both exceptions extend `\RuntimeException`.
- Contract of `convert(string $path)`:
  - `html`: HTML using only `p h1 h2 h3 h4 h5 h6 strong em u s br ul ol li table tr th td a img`.
  - `media`: list of `['token' => string, 'extension' => string, 'mime' => string, 'bytes' => string]`.
  - `warnings`: list of human-readable notes about what was skipped or flattened.
  - PDF only: `text`, the plain text (a cleaner feed for a full-text index than stripping tags from `html`).
- Images appear as `<img src="TOKEN">` where the token is random per conversion and per image. The caller stores `bytes` under a filename it generates and replaces the token with the final URL.
- Dependencies: PHP extensions `zip`, `dom`, `fileinfo` (Docx); for PDF the poppler binaries `/usr/bin/pdfinfo`, `/usr/bin/pdftotext` and `/usr/bin/pdftohtml` (Debian/Ubuntu package `poppler-utils`). Those absolute paths are fixed in the class.

## Contracts an edition must implement

No interface. The edition's job around a call:

1. Pass the path of a file it has received (for example a `$_FILES` temporary name). Check size and type up front if it wants earlier feedback; the converters re-check regardless.
2. Catch `DocxConversionException` / `PdfConversionException` and show `getMessage()` to the uploader. Messages are written for that: no filesystem paths, no stack detail.
3. Write each `media` entry itself, replace the tokens, and run the final HTML through its own purifier. The converter's tag set is deliberately small, but it is not a sanitiser.
4. For PDF, decide whether to offer the feature at all: it needs poppler and is unavailable when the binaries are missing. Editions check this before showing an import control.

## Key classes

```php
use RivetCore\KB\{DocxConverter, DocxConversionException, PdfConverter, PdfConversionException};

try {
    $result = DocxConverter::convert($tmpPath);          // array{html, media, warnings}
    $html = $result['html'];
    foreach ($result['media'] as $m) {
        $url = storeMedia($m['bytes'], $m['extension']); // your code, your filename
        $html = str_replace($m['token'], $url, $html);
    }
} catch (DocxConversionException $e) {
    flash($e->getMessage());
}

try {
    $pdf = PdfConverter::convert($tmpPath, $psr3Logger);  // array{html, text, media, warnings}; logger optional
} catch (PdfConversionException $e) {
    flash($e->getMessage());
}
```

`DocxConverter::convert()` is a static convenience over `(new DocxConverter())->run($path)`. `PdfConverter::convert()` takes an optional PSR-3 logger (default `Support\ErrorLogLogger`); the class is `final`, `DocxConverter` is not.

### Limits

DOCX (`DocxConverter`):

| Limit | Value |
|---|---|
| ZIP entries | 1024 |
| Declared total uncompressed size (checked before inflating) | 48 MiB |
| `word/document.xml` | 8 MiB |
| `word/_rels/document.xml.rels`, `word/numbering.xml` | 4 MiB each |
| Embedded image, each / total / count | 8 MiB / 24 MiB / 100 |
| Compression ratio (only entries of 4 MiB or more) | 500:1 |
| Nesting depth | 24 |
| Output HTML | 4 MiB (longer is truncated with a warning) |

PDF (`PdfConverter`):

| Limit | Value |
|---|---|
| Upload size | 32 MiB (refused before any child process starts) |
| Pages | 200 (enforced inside poppler; extra pages dropped with a warning) |
| pdfinfo / pdftotext / pdftohtml wall-clock timeout | 5 s / 20 s / 45 s, then SIGKILL |
| Layout XML / extracted text | 2 MiB / 1 MiB |
| Embedded image, each / total / count | 8 MiB / 24 MiB / 100 |
| One text line | 20,000 characters (truncated with a warning) |
| Output HTML | 4 MiB (cut at a paragraph boundary, with a warning) |

### What is converted

Both converters keep headings, paragraphs, bold/italic runs, lists and images in reading order. DOCX also keeps tables, `u`/`s` runs and hyperlinks. PDF recovers headings from font size and family, paragraphs from line geometry, and lists from markers and indentation.

Not recovered from a PDF: tables (cells arrive as ordinary text), multi-column reflow (detected by a wide gap and warned about, but flattened), footnote links, vector art, and anything in a scanned PDF without a text layer (fails with "Nothing could be read out of that PDF."). Several of these degrade silently into paragraphs.

### Rejections

Both throw for: unreadable or missing file, empty file, wrong format, oversize input, and a conversion that produces no content. DOCX also throws for a ZIP that is corrupt or has too many entries, a missing `word/document.xml` or body, an empty document, malformed XML, any XML with a DOCTYPE, and decompression bombs by declared size or ratio. PDF also throws for a missing magic `%PDF-`, password-protected files, files with copy restrictions, zero pages, timeouts, and a host without poppler or a writable temporary directory ("PDF import is not available on this server ...").

## Configuration

None. Budgets are class constants and are not configurable. The PDF scratch directory is created under the system temporary directory and removed on every exit path.

## How it fails

- Closed: any problem throws the module's exception with a safe message; a partial or unsafe result is never returned. The PDF tests assert that a truncated file either converts to safe text or throws.
- Skipped, not fatal: an image over a limit, a non-image or mislabelled file in `word/media/`, a linked (not embedded) image, an image path pointing outside `word/media/`, or a missing relationships part. Each adds a line to `warnings`.
- Logging: `PdfConverter` logs poppler's stderr (first 300 characters) at warning level when a stage exits non-zero. `DocxConverter` does not log.
- A PDF job that cannot start poppler reports the generic "not available on this server" message, not the OS error.

## Security notes

- DOCX: entries are read into memory by name and never extracted to disk, so ZIP path tricks are inert; XXE is shut off and any DOCTYPE rejects the document; hyperlinks are emitted only for `http`, `https` and `mailto`; relationship targets that leave `word/media/` are not followed; images must be an allow-listed raster type (PNG, JPEG, GIF, WebP, BMP) confirmed by both `finfo` and `getimagesizefromstring()`, and the stored extension comes from the sniffed type, never from the file name.
- PDF: poppler is run with an argument array (no shell), always against a copied file literally named `in.pdf` in a private scratch directory, with `LC_ALL=C`, `PATH=/usr/bin`, stdin from `/dev/null`, absolute binary paths, and a hard kill on timeout. Embedded JavaScript, launch and URI actions are not read from the PDF and cannot reach the output.
- Text is HTML-escaped (`<script>` in a document body arrives as `&lt;script&gt;`). Still purify before storing or displaying.
- Tokens are random per conversion, so document text cannot forge a media placeholder. Their prefix is `itflow-docx-media-` / `itflow-pdf-media-` for historical reasons.

## Used by

- RivetIT (`/var/www/mw-itflow.foleyit.com`): `src/KB/DocxConverter.php`, `PdfConverter.php`, `DocxConversionException.php` and `PdfConversionException.php` are `class_alias` shims onto the Core classes (namespace `ITFlow\KB`). `agent/post/kb_article.php` calls `\ITFlow\KB\DocxConverter::convert()` and `\ITFlow\KB\PdfConverter::convert()` and runs the media and HTML plumbing; the import dialogs are `agent/modals/kb_article/kb_article_import_docx.php` and `kb_article_import_pdf.php` (the PDF one checks that poppler is usable before offering import).
- RivetMSP (`/home/sysadmin/rivetmsp-beta`): the same aliases under `RivetMSP\KB` and the same call sites in `agent/post/kb_article.php`.

## Links

- [CHANGELOG](../../CHANGELOG.md): 0.6.0 (moved from the editions unchanged), 0.7.1 (PDF exit code on PHP 8.2), 0.16.0 (strict types, PHPStan baseline), 0.17.0 (PSR-3 logger on `PdfConverter::convert()`).
- [ADR-002: modules that stay in editions](../architecture/ADR-002-modules-that-stay-in-editions.md) (media tokens, URL rewriting, HTML import).
- [Knowledge](knowledge.md) renders credential references in the articles these converters create.
- Tests: `tests/Unit/Converters/DocxConverterTest.php`, `tests/Unit/Converters/PdfConverterTest.php` (skipped when poppler is missing), `tests/Unit/KbAndKnowledgeTest.php`; throwaway fixtures are built in `tests/Unit/Converters/ConverterTestCase.php`.
