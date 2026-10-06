<?php

declare(strict_types=1);

namespace RivetCore\KB;

/**
 * Native DOCX (WordprocessingML) -> HTML converter.
 *
 * A .docx is a ZIP. Everything this class needs lives in four well-known entries:
 *
 *   word/document.xml            the body
 *   word/_rels/document.xml.rels the rId -> target map (hyperlinks, images)
 *   word/numbering.xml           bullet-vs-number per numId/ilvl
 *   word/media/*                 the embedded image bytes
 *
 * There is NO new dependency here: ZipArchive + DOMDocument + fileinfo, all of
 * which the box already has. Nothing is ever extracted to disk by this class -
 * entries are read into memory by name with ZipArchive::getFromName() and the
 * caller decides where (and whether) media is written.
 *
 * THREAT MODEL
 * ------------
 * The input is a file a user uploaded. It is hostile until proven otherwise.
 *
 * 1. XXE / entity expansion.  See loadXml(). External entity resolution is shut
 *    off four separate ways, and any document carrying a DOCTYPE at all is
 *    rejected outright - WordprocessingML never has one.
 *
 * 2. Zip bomb.  See openArchive(). Entry count and the *declared* total
 *    uncompressed size are checked from the central directory before a single
 *    byte is inflated, plus a per-entry compression-ratio check. Because a
 *    central directory can lie, every actual read also passes a hard byte cap
 *    to getFromName() and re-checks the length it got back, so the real
 *    in-memory budget is enforced regardless of what the archive claimed.
 *
 * 3. Zip slip.  No entry name is ever used as a path, and no entry is ever
 *    written to disk by this class. Entries are read by fixed literal names;
 *    the only name derived from the file is a relationship Target under
 *    word/media/, which is validated by resolveMediaEntry() to be a simple
 *    relative name with no "..", no leading slash and no backslash, and is used
 *    solely as a ZIP lookup key - never as a filesystem path. Extracted media
 *    is handed back as raw bytes plus a converter-chosen extension; the caller
 *    generates the filename.
 *
 * 4. Content sniffing.  word/media/ can hold anything at all. Media is only
 *    accepted if finfo says its bytes are one of a small allow-list of raster
 *    image types AND getimagesizefromstring() agrees. The stored extension is
 *    derived from the sniffed type, never from the entry name.
 *
 * 5. HTML injection.  Every scrap of text and every attribute value is escaped
 *    with htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, UTF-8). The emitter can
 *    only ever produce the fixed tag set in self::ALLOWED_TAGS_NOTE and no
 *    attributes other than href/src/alt/colspan. Hyperlink targets are limited
 *    to http, https and mailto. This is deliberately independent of the
 *    HTMLPurifier pass the article body gets on the way out.
 *
 * OUTPUT
 * ------
 * convert() returns:
 *   [
 *     'html'     => string  HTML using only p, h1-h6, strong, em, u, s, br,
 *                           ul, ol, li, table, tr, th, td, a, img
 *     'media'    => array   [ ['token' => string, 'extension' => 'png',
 *                              'mime' => 'image/png', 'bytes' => string], ... ]
 *     'warnings' => array   human-readable notes (e.g. a list whose numbering
 *                           definition could not be resolved)
 *   ]
 *
 * Images are emitted as <img src="TOKEN"> where TOKEN is a random,
 * per-conversion, per-image opaque string. The caller writes the bytes wherever
 * it likes under a filename *it* generates, then str_replace()s the token for
 * the resulting URL. The token is random precisely so that document text can
 * never collide with it.
 *
 * @api
 */
class DocxConverter
{
    /** Documentation only - the emitter is a closed set of literal strings. */
    public const ALLOWED_TAGS_NOTE = 'p h1 h2 h3 h4 h5 h6 strong em u s br ul ol li table tr th td a img';

    // ---- Budgets ---------------------------------------------------------
    //
    // These are not round numbers picked by feel - they were measured on this
    // box (PHP 8.4.25, libxml 2.9.14) against synthetic documents built to be
    // the worst realistic shape, i.e. node-dense WordprocessingML with one
    // fully-propertied <w:r> per word:
    //
    //     word/document.xml     peak process RSS     wall
    //         4 MiB                  ~105 MB         0.4 s
    //         8 MiB                  ~173 MB         1.2 s
    //        12 MiB                  ~242 MB         1.4 s
    //        16 MiB                  ~310 MB         2.1 s
    //
    // The important detail is that a DOM lives in libxml's own heap, NOT in
    // PHP's allocator, so php.ini memory_limit (128M here) does not bound it -
    // only this cap does. 8 MiB of document.xml is somewhere between 200 and
    // 1300 pages of Word content, far more than any knowledge-base article,
    // and holds the worst case near 170 MB for one import.
    //
    // MAX_TOTAL_UNCOMPRESSED is the pre-inflate gate on the archive as a whole;
    // the per-part caps below are what actually bound memory, because each part
    // is read with a hard length limit rather than trusted.
    private const MAX_ENTRIES              = 1024;
    private const MAX_TOTAL_UNCOMPRESSED   = 50331648;  // 48 MiB
    private const MAX_DOCUMENT_XML_BYTES   = 8388608;   //  8 MiB
    private const MAX_RELS_XML_BYTES       = 4194304;   //  4 MiB
    private const MAX_NUMBERING_XML_BYTES  = 4194304;   //  4 MiB
    private const MAX_MEDIA_BYTES_EACH     = 8388608;   //  8 MiB
    private const MAX_MEDIA_BYTES_TOTAL    = 25165824;  // 24 MiB
    private const MAX_IMAGES               = 100;

    // Secondary, early-out bomb signal. Measured on the same fixtures, real
    // WordprocessingML deflates about 19:1 and ordinary prose about 3:1, while
    // a run of identical bytes deflates 300:1 and up. The floor matters: below
    // a few MiB the ratio is noisy and MAX_TOTAL_UNCOMPRESSED is the binding
    // constraint anyway, so the check only fires on a large entry that is also
    // absurdly compressible - which no genuine document part ever is.
    private const MAX_COMPRESSION_RATIO    = 500;
    private const RATIO_CHECK_MIN_SIZE     = 4194304;   //  4 MiB
    private const RATIO_CHECK_MIN_COMP     = 1024;

    // Structural limits - a deeply nested table/sdt tree would otherwise
    // recurse until the stack dies. kb_articles.kb_article_content is a
    // MEDIUMTEXT (16 MiB), and the caller escapes the HTML twice over (once
    // for kb_article_content, once for kb_article_content_raw) before it ever
    // reaches the database, so 4 MiB of HTML is the point where the DB column
    // and PHP's own memory_limit both still have comfortable headroom.
    private const MAX_DEPTH                = 24;
    private const MAX_HTML_BYTES           = 4194304;   //  4 MiB

    // ---- Accepted media --------------------------------------------------
    private const IMAGE_MIME_EXT = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/bmp'  => 'bmp',
    ];
    private const IMAGE_TYPES = [
        IMAGETYPE_PNG,
        IMAGETYPE_JPEG,
        IMAGETYPE_GIF,
        IMAGETYPE_WEBP,
        IMAGETYPE_BMP,
    ];

    private const REL_NS = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'http://purl.oclc.org/ooxml/officeDocument/relationships',
    ];

    /** @var array<string,array{target:string,external:bool}> rId => relationship */
    private array $rels = [];

    /** @var array<string,array<int,string>> numId => ilvl => numFmt */
    private array $numbering = [];

    /** @var array<int,array{token:string,extension:string,mime:string,bytes:string}> */
    private array $media = [];

    /** @var array<string,string> zip entry name => already-issued token */
    private array $mediaByEntry = [];

    /** @var array<int,string> */
    private array $warnings = [];

    private int $mediaBytesUsed = 0;
    private int $htmlBytesUsed = 0;
    private string $tokenPrefix = '';
    private bool $truncated = false;
    private bool $numberingLoaded = false;

    /**
     * Convert a .docx on disk.
     *
     * @param  string $path Absolute path to the uploaded file (e.g. a $_FILES tmp_name).
     * @return array{html:string,media:array,warnings:array}
     * @throws DocxConversionException on anything malformed, oversized or hostile.
     */
    public static function convert(string $path): array
    {
        return (new self())->run($path);
    }

    /**
     * @return array{html:string,media:array,warnings:array}
     * @throws DocxConversionException
     */
    public function run(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new DocxConversionException('The uploaded file could not be read.');
        }

        $this->tokenPrefix = 'itflow-docx-media-' . bin2hex(random_bytes(16)) . '-';

        $zip = $this->openArchive($path);

        try {
            $documentXml = $this->readEntry($zip, 'word/document.xml', self::MAX_DOCUMENT_XML_BYTES);
            if ($documentXml === null) {
                throw new DocxConversionException('That file is not a Word document (word/document.xml is missing).');
            }

            $this->loadRelationships($zip);
            $this->loadNumbering($zip);

            $doc = $this->loadXml($documentXml, 'word/document.xml');

            $body = null;
            foreach ($doc->documentElement?->childNodes ?? [] as $child) {
                if ($child instanceof \DOMElement && $child->localName === 'body') {
                    $body = $child;
                    break;
                }
            }
            if ($body === null) {
                throw new DocxConversionException('That Word document has no body.');
            }

            $html = $this->renderBlockContainer($zip, $body, 0);
        } finally {
            $zip->close();
        }

        $html = trim($html);

        if ($html === '') {
            throw new DocxConversionException('That Word document appears to be empty - nothing was imported.');
        }

        if ($this->truncated) {
            $this->warn('The document was longer than the ' . round(self::MAX_HTML_BYTES / 1048576) . ' MB import limit and was truncated.');
        }

        return [
            'html'     => $html,
            'media'    => array_values($this->media),
            'warnings' => $this->warnings,
        ];
    }

    // =====================================================================
    // ZIP
    // =====================================================================

    /**
     * Open the archive and spend the zip-bomb budget check BEFORE inflating
     * anything. ZipArchive::statIndex() reads the central directory only - no
     * decompression happens here.
     *
     * @throws DocxConversionException
     */
    private function openArchive(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();

        $opened = $zip->open($path, \ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new DocxConversionException('That file is not a valid .docx (it is not a readable ZIP archive).');
        }

        $count = $zip->numFiles;
        if ($count <= 0) {
            $zip->close();
            throw new DocxConversionException('That .docx is empty.');
        }
        if ($count > self::MAX_ENTRIES) {
            $zip->close();
            throw new DocxConversionException('That .docx contains too many parts (' . $count . ') and was rejected.');
        }

        $declaredTotal = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();
                throw new DocxConversionException('That .docx has a corrupt directory entry and was rejected.');
            }

            $size = (int) ($stat['size'] ?? 0);
            $comp = (int) ($stat['comp_size'] ?? 0);

            if ($size < 0 || $comp < 0) {
                $zip->close();
                throw new DocxConversionException('That .docx declares a nonsensical entry size and was rejected.');
            }

            $declaredTotal += $size;
            if ($declaredTotal > self::MAX_TOTAL_UNCOMPRESSED) {
                $zip->close();
                throw new DocxConversionException(
                    'That .docx expands to more than ' . round(self::MAX_TOTAL_UNCOMPRESSED / 1048576)
                    . ' MB and was rejected as a decompression bomb.'
                );
            }

            if ($size >= self::RATIO_CHECK_MIN_SIZE
                && $comp >= self::RATIO_CHECK_MIN_COMP
                && $size / $comp > self::MAX_COMPRESSION_RATIO
            ) {
                $zip->close();
                throw new DocxConversionException(
                    'That .docx contains an entry with a ' . (int) ($size / $comp)
                    . ':1 compression ratio and was rejected as a decompression bomb.'
                );
            }
        }

        return $zip;
    }

    /**
     * Read one entry by literal name, with a hard byte cap that is enforced on
     * the bytes actually produced - not on the size the archive claimed.
     *
     * getFromName()'s $len argument stops the inflate at $cap + 1 bytes, so a
     * lying central directory cannot make us allocate more than the budget; if
     * we get back more than $cap we know the entry really was oversized.
     *
     * @throws DocxConversionException
     */
    private function readEntry(\ZipArchive $zip, string $name, int $cap): ?string
    {
        // Literal, hard-coded names only - never an attacker-supplied string
        // used as a path. This is a ZIP lookup key, not a filesystem path.
        $data = $zip->getFromName($name, $cap + 1, \ZipArchive::FL_NOCASE);

        if ($data === false) {
            return null;
        }

        if (strlen($data) > $cap) {
            // $name is a literal from this class, never user input, and the
            // caller escapes the message before it reaches a page.
            throw new DocxConversionException(
                'A part of that .docx (' . $name . ') is larger than its '
                . round($cap / 1048576, 1) . ' MB import limit and was rejected.'
            );
        }

        return $data;
    }

    // =====================================================================
    // XML
    // =====================================================================

    /**
     * Parse XML with external entities comprehensively disabled.
     *
     * WHY THIS HOLDS ON PHP 8.4 / libxml2 2.9.x, four independent layers:
     *
     *  1. libxml_set_external_entity_loader(fn => null). This replaces libxml's
     *     entity loader callback for the duration of the parse. Every external
     *     fetch - SYSTEM/PUBLIC entities, external DTD subsets, XIncludes -
     *     goes through that callback, and returning null makes it fail. This is
     *     the successor to the removed libxml_disable_entity_loader() and is
     *     the strongest lever PHP exposes. The previous loader is captured with
     *     libxml_get_external_entity_loader() (PHP 8.4+) and restored in a
     *     finally block so we never leave global state changed.
     *  2. LIBXML_NONET, which makes libxml refuse any network-backed resource
     *     even if a loader were reached (this is what stops http:// SYSTEM ids).
     *  3. $doc->resolveExternals = false and $doc->substituteEntities = false,
     *     and LIBXML_NOENT is deliberately NOT passed. LIBXML_NOENT is a
     *     misnomer - it *enables* entity substitution - so its absence means
     *     even purely internal entities are left as unexpanded reference nodes.
     *     That is what defuses "billion laughs": there is no expansion step to
     *     blow up. (Whether libxml2 was built with entity limits on is then
     *     irrelevant to us.)
     *  4. Belt and braces, a DOCTYPE is rejected outright - before the parse by
     *     byte scan, and after it via DOMDocument::$doctype. WordprocessingML
     *     has no DTD and never legitimately carries one, so fail-closed costs
     *     nothing and makes layers 1-3 unreachable in the first place.
     *
     * @throws DocxConversionException
     */
    private function loadXml(string $xml, string $label): \DOMDocument
    {
        // Layer 4a - refuse anything with a document type declaration or an
        // entity declaration at all. WordprocessingML never has one.
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new DocxConversionException(
                'That .docx contains an XML document type declaration in ' . $label
                . ' and was rejected (possible XXE / entity-expansion attack).'
            );
        }

        $previousLoader = libxml_get_external_entity_loader();
        $previousErrors = libxml_use_internal_errors(true);

        try {
            // Layer 1 - no external resource can ever be fetched.
            libxml_set_external_entity_loader(static fn () => null);

            $doc = new \DOMDocument();
            // Layer 3.
            $doc->resolveExternals   = false;
            $doc->substituteEntities = false;
            $doc->recover            = false;
            $doc->validateOnParse    = false;

            // Layer 2 - LIBXML_NONET. LIBXML_NOENT is deliberately absent.
            $ok = $doc->loadXML(
                $xml,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOCDATA
            );

            if ($ok === false || $doc->documentElement === null) {
                throw new DocxConversionException('That .docx contains malformed XML in ' . $label . '.');
            }

            // Layer 4b - if a DOCTYPE somehow survived the byte scan, stop here.
            if ($doc->doctype !== null) {
                throw new DocxConversionException(
                    'That .docx contains an XML document type declaration in ' . $label
                    . ' and was rejected (possible XXE / entity-expansion attack).'
                );
            }

            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
            libxml_set_external_entity_loader($previousLoader);
        }
    }

    /** Namespace-agnostic attribute read, optionally restricted to a namespace set. */
    private static function attr(\DOMElement $el, string $local, ?array $ns = null): ?string
    {
        foreach ($el->attributes as $a) {
            if ($a->localName !== $local) {
                continue;
            }
            if ($ns !== null && !in_array($a->namespaceURI, $ns, true)) {
                continue;
            }
            return $a->value;
        }
        return null;
    }

    /** First direct child element with this local name. */
    private static function child(\DOMElement $el, string $local): ?\DOMElement
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->localName === $local) {
                return $c;
            }
        }
        return null;
    }

    /** First descendant element with this local name (depth-first). */
    private static function descendant(\DOMElement $el, string $local, int $depth = 0): ?\DOMElement
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }
        foreach ($el->childNodes as $c) {
            if (!$c instanceof \DOMElement) {
                continue;
            }
            if ($c->localName === $local) {
                return $c;
            }
            $found = self::descendant($c, $local, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * A w:XXX toggle property. Present means on, unless it carries
     * w:val="0" / "false" / "off".
     */
    private static function toggleOn(?\DOMElement $el): bool
    {
        if ($el === null) {
            return false;
        }
        $val = self::attr($el, 'val');
        if ($val === null) {
            return true;
        }
        return !in_array(strtolower(trim($val)), ['0', 'false', 'off', 'none'], true);
    }

    // =====================================================================
    // Relationships and numbering
    // =====================================================================

    private function loadRelationships(\ZipArchive $zip): void
    {
        $xml = $this->readEntry($zip, 'word/_rels/document.xml.rels', self::MAX_RELS_XML_BYTES);
        if ($xml === null) {
            $this->warn('This document has no relationship part, so hyperlinks and images could not be resolved.');
            return;
        }

        $doc = $this->loadXml($xml, 'word/_rels/document.xml.rels');

        foreach ($doc->documentElement->childNodes as $node) {
            if (!$node instanceof \DOMElement || $node->localName !== 'Relationship') {
                continue;
            }
            $id     = self::attr($node, 'Id');
            $target = self::attr($node, 'Target');
            if ($id === null || $id === '' || $target === null || $target === '') {
                continue;
            }
            $mode = strtolower((string) (self::attr($node, 'TargetMode') ?? ''));

            $this->rels[$id] = [
                'target'   => $target,
                'external' => $mode === 'external',
            ];
        }
    }

    /**
     * Build numId => ilvl => numFmt from word/numbering.xml so a list can be
     * emitted as <ol> or <ul> correctly instead of guessed at.
     *
     * Resolution path: w:num[@w:numId] -> w:abstractNumId[@w:val] ->
     * w:abstractNum[@w:abstractNumId] -> w:lvl[@w:ilvl] -> w:numFmt[@w:val],
     * with w:num/w:lvlOverride/w:lvl taking precedence when present.
     *
     * Not resolved: an abstract definition that only carries w:numStyleLink,
     * which forwards into word/styles.xml. Those fall through to the <ul>
     * default and raise a warning rather than silently guessing <ol>.
     */
    private function loadNumbering(\ZipArchive $zip): void
    {
        $xml = $this->readEntry($zip, 'word/numbering.xml', self::MAX_NUMBERING_XML_BYTES);
        if ($xml === null) {
            return;
        }

        $doc = $this->loadXml($xml, 'word/numbering.xml');
        $root = $doc->documentElement;

        // abstractNumId => ilvl => numFmt
        $abstract = [];
        foreach ($root->childNodes as $node) {
            if (!$node instanceof \DOMElement || $node->localName !== 'abstractNum') {
                continue;
            }
            $absId = self::attr($node, 'abstractNumId');
            if ($absId === null) {
                continue;
            }
            $abstract[$absId] = $this->collectLevels($node);
        }

        foreach ($root->childNodes as $node) {
            if (!$node instanceof \DOMElement || $node->localName !== 'num') {
                continue;
            }
            $numId = self::attr($node, 'numId');
            if ($numId === null) {
                continue;
            }

            $levels = [];

            $absRef = self::child($node, 'abstractNumId');
            if ($absRef !== null) {
                $absId = self::attr($absRef, 'val');
                if ($absId !== null && isset($abstract[$absId])) {
                    $levels = $abstract[$absId];
                }
            }

            // w:lvlOverride wins over the abstract definition.
            foreach ($node->childNodes as $ov) {
                if (!$ov instanceof \DOMElement || $ov->localName !== 'lvlOverride') {
                    continue;
                }
                $lvl = self::child($ov, 'lvl');
                if ($lvl === null) {
                    continue;
                }
                $ilvl = self::attr($lvl, 'ilvl') ?? self::attr($ov, 'ilvl');
                $fmt  = self::child($lvl, 'numFmt');
                if ($ilvl !== null && $fmt !== null) {
                    $val = self::attr($fmt, 'val');
                    if ($val !== null) {
                        $levels[(int) $ilvl] = strtolower($val);
                    }
                }
            }

            $this->numbering[$numId] = $levels;
        }

        $this->numberingLoaded = true;
    }

    /** @return array<int,string> ilvl => numFmt */
    private function collectLevels(\DOMElement $abstractNum): array
    {
        $levels = [];
        foreach ($abstractNum->childNodes as $lvl) {
            if (!$lvl instanceof \DOMElement || $lvl->localName !== 'lvl') {
                continue;
            }
            $ilvl = self::attr($lvl, 'ilvl');
            $fmt  = self::child($lvl, 'numFmt');
            if ($ilvl === null || $fmt === null) {
                continue;
            }
            $val = self::attr($fmt, 'val');
            if ($val !== null) {
                $levels[(int) $ilvl] = strtolower($val);
            }
        }
        return $levels;
    }

    /**
     * <ul> or <ol> for this numId/ilvl. Defaults to <ul> - and says so - when
     * the numbering definition cannot be resolved, rather than guessing <ol>.
     */
    private function listKind(?string $numId, int $ilvl): string
    {
        if ($numId === null) {
            return 'ul';
        }

        $fmt = $this->numbering[$numId][$ilvl] ?? null;

        if ($fmt === null && isset($this->numbering[$numId])) {
            // Word omits deeper levels sometimes - fall back to the nearest
            // shallower level that is defined.
            for ($i = $ilvl - 1; $i >= 0; $i--) {
                if (isset($this->numbering[$numId][$i])) {
                    $fmt = $this->numbering[$numId][$i];
                    break;
                }
            }
        }

        if ($fmt === null) {
            $this->warn(
                $this->numberingLoaded
                    ? 'A list in this document uses a numbering definition that could not be resolved (numId ' . $numId . '); it was imported as a bulleted list.'
                    : 'This document has no numbering definitions, so every list was imported as a bulleted list.'
            );
            return 'ul';
        }

        if ($fmt === 'bullet' || $fmt === 'none') {
            return 'ul';
        }

        return 'ol';
    }

    // =====================================================================
    // Block rendering
    // =====================================================================

    /**
     * Render the block-level children of a container (w:body, w:tc, w:sdtContent...)
     * building the list stack as we go.
     */
    private function renderBlockContainer(\ZipArchive $zip, \DOMElement $container, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '';
        }

        $out       = '';
        $listStack = [];   // list of 'ul'|'ol'
        $liOpen    = false;

        foreach ($container->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            switch ($node->localName) {
                case 'p':
                    $block = $this->renderParagraph($zip, $node, $depth + 1);
                    if ($block === null) {
                        break;
                    }
                    if ($block['type'] === 'li') {
                        $out .= $this->openListItem($listStack, $liOpen, $block['kind'], $block['level']);
                        $out .= $block['html'];
                    } else {
                        $out .= $this->closeLists($listStack, $liOpen);
                        $out .= $block['html'];
                    }
                    break;

                case 'tbl':
                    $out .= $this->closeLists($listStack, $liOpen);
                    $out .= $this->renderTable($zip, $node, $depth + 1);
                    break;

                case 'sdt':
                    $content = self::child($node, 'sdtContent');
                    if ($content !== null) {
                        $out .= $this->closeLists($listStack, $liOpen);
                        $out .= $this->renderBlockContainer($zip, $content, $depth + 1);
                    }
                    break;

                default:
                    // sectPr, bookmarkStart/End, proofErr, commentRangeStart... - nothing to emit.
                    break;
            }

            if ($this->truncated) {
                break;
            }
        }

        $out .= $this->closeLists($listStack, $liOpen);

        return $out;
    }

    /**
     * Move the open-list stack to the depth/kind this item wants and open its
     * <li>. A deeper list is opened *inside* the currently open <li>, which is
     * what produces correctly nested markup rather than sibling lists.
     *
     * @param array<int,string> $listStack
     */
    private function openListItem(array &$listStack, bool &$liOpen, string $kind, int $level): string
    {
        $out = '';

        // Clamp: never open more than one level deeper than we currently are,
        // so a document that jumps straight to ilvl 5 cannot spawn five lists.
        $target = min($level + 1, count($listStack) + 1);
        $target = max($target, 1);

        // Descend.
        while (count($listStack) < $target) {
            if ($listStack !== [] && !$liOpen) {
                // A sublist must live inside an <li>.
                $out .= $this->account('<li>');
                $liOpen = true;
            }
            $out .= $this->account('<' . $kind . '>');
            $listStack[] = $kind;
            $liOpen = false;
        }

        // Ascend.
        while (count($listStack) > $target) {
            if ($liOpen) {
                $out .= $this->account('</li>');
            }
            $out .= $this->account('</' . array_pop($listStack) . '>');
            $liOpen = $listStack !== [];
        }

        // Same depth, different kind - close and reopen.
        if ($listStack[$target - 1] !== $kind) {
            if ($liOpen) {
                $out .= $this->account('</li>');
            }
            $out .= $this->account('</' . array_pop($listStack) . '>');
            $out .= $this->account('<' . $kind . '>');
            $listStack[] = $kind;
            $liOpen = false;
        }

        if ($liOpen) {
            $out .= $this->account('</li>');
            $liOpen = false;
        }

        $out .= $this->account('<li>');
        $liOpen = true;

        return $out;
    }

    /** @param array<int,string> $listStack */
    private function closeLists(array &$listStack, bool &$liOpen): string
    {
        $out = '';
        while ($listStack !== []) {
            if ($liOpen) {
                $out .= $this->account('</li>');
            }
            $out .= $this->account('</' . array_pop($listStack) . '>');
            $liOpen = $listStack !== [];
        }
        $liOpen = false;
        return $out;
    }

    /**
     * @return array{type:string,html:string,kind:string,level:int}|null
     */
    private function renderParagraph(\ZipArchive $zip, \DOMElement $p, int $depth): ?array
    {
        $pPr = self::child($p, 'pPr');

        $inner = $this->renderInline($zip, $p, $depth, []);

        // A heading or list marker is worth keeping even with no text; a plain
        // empty paragraph is just vertical space we do not need to reproduce.
        $isBlank = trim(strip_tags($inner)) === '' && !str_contains($inner, '<img');

        // --- List item? ---
        if ($pPr !== null) {
            $numPr = self::child($pPr, 'numPr');
            if ($numPr !== null) {
                $numIdEl = self::child($numPr, 'numId');
                $ilvlEl  = self::child($numPr, 'ilvl');

                $numId = $numIdEl !== null ? self::attr($numIdEl, 'val') : null;
                $ilvl  = $ilvlEl !== null ? (int) (self::attr($ilvlEl, 'val') ?? '0') : 0;
                $ilvl  = max(0, min($ilvl, 8));

                // numId 0 means "this paragraph is explicitly not numbered".
                if ($numId !== null && $numId !== '0') {
                    return [
                        'type'  => 'li',
                        'kind'  => $this->listKind($numId, $ilvl),
                        'level' => $ilvl,
                        'html'  => $inner,
                    ];
                }
            }
        }

        // --- Heading? ---
        $tag = 'p';
        if ($pPr !== null) {
            $styleEl = self::child($pPr, 'pStyle');
            if ($styleEl !== null) {
                $style = strtolower(str_replace([' ', '-', '_'], '', (string) self::attr($styleEl, 'val')));
                if (preg_match('/^heading([1-6])$/', $style, $m) === 1) {
                    $tag = 'h' . $m[1];
                } elseif ($style === 'title') {
                    $tag = 'h1';
                } elseif ($style === 'subtitle') {
                    $tag = 'h2';
                }
            }
        }

        if ($isBlank && $tag === 'p') {
            return null;
        }

        // Only the wrapper is charged here - $inner was charged as it was built.
        $this->account('<' . $tag . '></' . $tag . '>');

        return [
            'type'  => 'p',
            'kind'  => '',
            'level' => 0,
            'html'  => '<' . $tag . '>' . $inner . '</' . $tag . '>',
        ];
    }

    private function renderTable(\ZipArchive $zip, \DOMElement $tbl, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '';
        }

        $rows = '';
        foreach ($tbl->childNodes as $tr) {
            if (!$tr instanceof \DOMElement || $tr->localName !== 'tr') {
                continue;
            }

            // A header row (w:trPr/w:tblHeader) becomes <th>.
            $isHeader = false;
            $trPr = self::child($tr, 'trPr');
            if ($trPr !== null) {
                $isHeader = self::toggleOn(self::child($trPr, 'tblHeader'));
            }

            $cells = '';
            foreach ($tr->childNodes as $tc) {
                if (!$tc instanceof \DOMElement || $tc->localName !== 'tc') {
                    continue;
                }

                $colspan = 1;
                $tcPr = self::child($tc, 'tcPr');
                if ($tcPr !== null) {
                    $span = self::child($tcPr, 'gridSpan');
                    if ($span !== null) {
                        $colspan = max(1, min(64, (int) (self::attr($span, 'val') ?? '1')));
                    }
                }

                $cellTag  = $isHeader ? 'th' : 'td';
                $cellHtml = $this->renderBlockContainer($zip, $tc, $depth + 1);

                $open = '<' . $cellTag . ($colspan > 1 ? ' colspan="' . $colspan . '"' : '') . '>';

                // $cellHtml already paid its own way; charge only the cell tags.
                $this->account($open . '</' . $cellTag . '>');

                $cells .= $open . $cellHtml . '</' . $cellTag . '>';
            }

            if ($cells !== '') {
                $rows .= $this->account('<tr>') . $cells . $this->account('</tr>');
            }
        }

        if ($rows === '') {
            return '';
        }

        return $this->account('<table>') . $rows . $this->account('</table>');
    }

    // =====================================================================
    // Inline rendering
    // =====================================================================

    /**
     * Walk the inline content of a paragraph-ish element.
     *
     * @param array{b?:bool,i?:bool,u?:bool,s?:bool} $inheritedFormat
     */
    private function renderInline(\ZipArchive $zip, \DOMElement $parent, int $depth, array $inheritedFormat): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '';
        }

        $out = '';

        foreach ($parent->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            switch ($node->localName) {
                case 'r':
                    $out .= $this->renderRun($zip, $node, $depth + 1, $inheritedFormat);
                    break;

                case 'hyperlink':
                    $out .= $this->renderHyperlink($zip, $node, $depth + 1, $inheritedFormat);
                    break;

                case 'del':
                case 'moveFrom':
                    // Tracked deletion - the text is not part of the document.
                    break;

                case 'pPr':
                    // Paragraph properties, not content.
                    break;

                case 'ins':
                case 'moveTo':
                case 'smartTag':
                case 'bdo':
                case 'dir':
                case 'fldSimple':
                case 'sdtContent':
                    $out .= $this->renderInline($zip, $node, $depth + 1, $inheritedFormat);
                    break;

                case 'sdt':
                    $content = self::child($node, 'sdtContent');
                    if ($content !== null) {
                        $out .= $this->renderInline($zip, $content, $depth + 1, $inheritedFormat);
                    }
                    break;

                default:
                    break;
            }

            if ($this->truncated) {
                break;
            }
        }

        return $out;
    }

    /** @param array $inheritedFormat */
    private function renderRun(\ZipArchive $zip, \DOMElement $r, int $depth, array $inheritedFormat): string
    {
        $fmt = $inheritedFormat;

        $rPr = self::child($r, 'rPr');
        if ($rPr !== null) {
            if (self::child($rPr, 'b') !== null) {
                $fmt['b'] = self::toggleOn(self::child($rPr, 'b'));
            }
            if (self::child($rPr, 'i') !== null) {
                $fmt['i'] = self::toggleOn(self::child($rPr, 'i'));
            }
            if (self::child($rPr, 'u') !== null) {
                $fmt['u'] = self::toggleOn(self::child($rPr, 'u'));
            }
            if (self::child($rPr, 'strike') !== null) {
                $fmt['s'] = self::toggleOn(self::child($rPr, 'strike'));
            }
            if (self::child($rPr, 'dstrike') !== null) {
                $fmt['s'] = self::toggleOn(self::child($rPr, 'dstrike'));
            }
        }

        $inner = '';

        foreach ($r->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            switch ($node->localName) {
                case 't':
                    $inner .= $this->esc($node->textContent);
                    break;

                case 'delText':
                    // Deleted text - not content.
                    break;

                case 'tab':
                    $inner .= ' ';
                    break;

                case 'br':
                case 'cr':
                    $inner .= '<br>';
                    break;

                case 'noBreakHyphen':
                    $inner .= '-';
                    break;

                case 'softHyphen':
                    break;

                case 'sym':
                    // A symbol-font glyph; there is no faithful Unicode mapping
                    // without the font, so emit nothing rather than mojibake.
                    break;

                case 'drawing':
                case 'pict':
                case 'object':
                    $inner .= $this->renderImage($zip, $node);
                    break;

                default:
                    break;
            }
        }

        if ($inner === '') {
            return '';
        }

        // Wrap innermost-first so nesting is stable and always balanced.
        if (!empty($fmt['s'])) {
            $inner = '<s>' . $inner . '</s>';
        }
        if (!empty($fmt['u'])) {
            $inner = '<u>' . $inner . '</u>';
        }
        if (!empty($fmt['i'])) {
            $inner = '<em>' . $inner . '</em>';
        }
        if (!empty($fmt['b'])) {
            $inner = '<strong>' . $inner . '</strong>';
        }

        return $this->budget($inner);
    }

    private function renderHyperlink(\ZipArchive $zip, \DOMElement $link, int $depth, array $inheritedFormat): string
    {
        // $inner charges itself as its runs are built; only the <a> wrapper is
        // charged below, so a link never pays for its own text twice.
        $inner = $this->renderInline($zip, $link, $depth, $inheritedFormat);
        if ($inner === '') {
            return '';
        }

        // r:id resolves through word/_rels/document.xml.rels.
        $rId = self::attr($link, 'id', self::REL_NS) ?? self::attr($link, 'id');

        $href = null;
        if ($rId !== null && isset($this->rels[$rId])) {
            $href = $this->safeUrl($this->rels[$rId]['target']);
        }

        if ($href === null) {
            // Internal bookmark, missing relationship, or a scheme we refuse to
            // emit - keep the text, drop the link.
            return $inner;
        }

        $open = '<a href="' . $this->esc($href) . '" rel="noopener noreferrer" target="_blank">';

        return $this->account($open) . $inner . $this->account('</a>');
    }

    /**
     * Only http, https and mailto are ever emitted. Everything else
     * (javascript:, data:, vbscript:, file:, unknown schemes) is dropped.
     */
    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            return null;
        }

        // Reject control characters outright - they are how scheme filters get
        // smuggled past ("java\0script:").
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        if (preg_match('#^(https?|mailto):#i', $url) !== 1) {
            return null;
        }

        return $url;
    }

    // =====================================================================
    // Images
    // =====================================================================

    /**
     * Find the embedded image relationship inside a w:drawing / w:pict and,
     * if its bytes really are a raster image, emit an <img> pointing at an
     * opaque token the caller will substitute.
     */
    private function renderImage(\ZipArchive $zip, \DOMElement $node): string
    {
        $rId = null;

        // DrawingML: a:blip/@r:embed
        $blip = self::descendant($node, 'blip');
        if ($blip !== null) {
            $rId = self::attr($blip, 'embed', self::REL_NS) ?? self::attr($blip, 'embed');
        }

        // Legacy VML: v:imagedata/@r:id
        if ($rId === null) {
            $imagedata = self::descendant($node, 'imagedata');
            if ($imagedata !== null) {
                $rId = self::attr($imagedata, 'id', self::REL_NS);
            }
        }

        if ($rId === null || !isset($this->rels[$rId])) {
            return '';
        }

        $alt = '';
        $docPr = self::descendant($node, 'docPr');
        if ($docPr !== null) {
            $alt = (string) (self::attr($docPr, 'descr') ?? self::attr($docPr, 'name') ?? '');
        }

        $token = $this->extractMedia($zip, $rId);
        if ($token === null) {
            return '';
        }

        return $this->budget(
            '<img src="' . $this->esc($token) . '" alt="' . $this->esc(mb_substr($alt, 0, 200)) . '">'
        );
    }

    /**
     * Pull one media part out of the archive, validate that it really is a
     * raster image, and register it. Returns the substitution token, or null.
     */
    private function extractMedia(\ZipArchive $zip, string $rId): ?string
    {
        $rel = $this->rels[$rId];

        if ($rel['external']) {
            $this->warn('A linked (not embedded) image was skipped - only images stored inside the document are imported.');
            return null;
        }

        $entry = $this->resolveMediaEntry($rel['target']);
        if ($entry === null) {
            return null;
        }

        // Already pulled this exact part for another <img>? Reuse it.
        if (isset($this->mediaByEntry[$entry])) {
            return $this->mediaByEntry[$entry];
        }

        if (count($this->media) >= self::MAX_IMAGES) {
            $this->warn('This document contains more than ' . self::MAX_IMAGES . ' images; the extras were skipped.');
            return null;
        }

        try {
            $bytes = $this->readEntry($zip, $entry, self::MAX_MEDIA_BYTES_EACH);
        } catch (DocxConversionException) {
            $this->warn('An oversized image in this document was skipped.');
            return null;
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $this->mediaBytesUsed += strlen($bytes);
        if ($this->mediaBytesUsed > self::MAX_MEDIA_BYTES_TOTAL) {
            $this->warn('This document carries more than ' . round(self::MAX_MEDIA_BYTES_TOTAL / 1048576) . ' MB of images; the extras were skipped.');
            $this->mediaBytesUsed -= strlen($bytes);
            return null;
        }

        // word/media/ can legally hold ANY bytes - an EXE, a PHP file, an SVG
        // with script in it. Two independent sniffs must agree that this is a
        // raster image of a type we allow, and the extension we hand back is
        // derived from that verdict - never from the entry name.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->buffer($bytes);
        $mime  = strtolower(trim(explode(';', $mime)[0]));

        if (!isset(self::IMAGE_MIME_EXT[$mime])) {
            $this->warn('An embedded file that is not an image was skipped.');
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset($info[2]) || !in_array($info[2], self::IMAGE_TYPES, true)) {
            $this->warn('An embedded file that claimed to be an image but does not decode was skipped.');
            return null;
        }

        // And the two sniffs must be talking about the same thing.
        $sniffedMime = image_type_to_mime_type($info[2]);
        if (strtolower($sniffedMime) !== $mime) {
            $this->warn('An embedded image with a mismatched type was skipped.');
            return null;
        }

        $token = $this->tokenPrefix . count($this->media);

        $this->media[] = [
            'token'     => $token,
            'extension' => self::IMAGE_MIME_EXT[$mime],
            'mime'      => $mime,
            'bytes'     => $bytes,
        ];
        $this->mediaByEntry[$entry] = $token;

        return $token;
    }

    /**
     * Turn a relationship Target into a ZIP entry NAME - never a filesystem
     * path, and never something that can escape word/media/.
     *
     * Targets are relative to word/, e.g. "media/image1.png", and Word also
     * emits "/word/media/image1.png" and (rarely) "../word/media/image1.png".
     * Anything that is not, after normalisation, exactly word/media/<simple
     * name> is refused - which is the zip-slip guard, even though nothing here
     * is ever written using this string.
     */
    private function resolveMediaEntry(string $target): ?string
    {
        $target = trim($target);

        if ($target === '' || strlen($target) > 512) {
            return null;
        }

        // No backslashes, no NUL, no control characters, no URL-encoding games.
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $target) === 1 || str_contains($target, '%')) {
            return null;
        }

        // Strip a query/fragment if some producer added one.
        $target = preg_split('/[?#]/', $target)[0];

        // Normalise to a package-root-relative name.
        if ($target[0] === '/') {
            $path = ltrim($target, '/');
        } else {
            $path = 'word/' . $target;
        }

        // Resolve . and .. arithmetically so "../word/media/x.png" works while
        // "../../etc/passwd" cannot survive.
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    // Tried to climb above the package root - a zip-slip attempt.
                    $this->warn('An image reference pointing outside word/media/ was ignored.');
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        $entry = implode('/', $parts);

        // The only place we will read media from.
        if (preg_match('#^word/media/[A-Za-z0-9._-]+$#', $entry) !== 1) {
            $this->warn('An image reference pointing outside word/media/ was ignored.');
            return null;
        }

        return $entry;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function esc(string $text): string
    {
        if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Charge a piece of CONTENT against the output budget and drop it once the
     * budget is spent. Only ever called on bytes nothing else has charged for,
     * so the running total stays honest.
     */
    private function budget(string $fragment): string
    {
        if ($this->truncated) {
            return '';
        }
        $this->htmlBytesUsed += strlen($fragment);
        if ($this->htmlBytesUsed > self::MAX_HTML_BYTES) {
            $this->truncated = true;
            return '';
        }
        return $fragment;
    }

    /**
     * Charge STRUCTURE (block wrappers, list and table tags) against the same
     * budget, but always hand the markup back. Dropping a closing tag to save
     * bytes would emit broken HTML, so structure only ever trips the flag - the
     * walk then stops at the next block boundary, which bounds the overshoot to
     * one block instead of leaving unbalanced tags behind.
     */
    private function account(string $markup): string
    {
        $this->htmlBytesUsed += strlen($markup);
        if ($this->htmlBytesUsed > self::MAX_HTML_BYTES) {
            $this->truncated = true;
        }
        return $markup;
    }

    private function warn(string $message): void
    {
        if (!in_array($message, $this->warnings, true) && count($this->warnings) < 25) {
            $this->warnings[] = $message;
        }
    }
}
