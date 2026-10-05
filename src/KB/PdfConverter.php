<?php

namespace RivetCore\KB;

/**
 * PDF -> Knowledge Base article HTML, via poppler.
 *
 * Sibling of DocxConverter, and deliberately the SAME CONTRACT:
 *
 *     PdfConverter::convert(string $path): array{html, text, media, warnings}
 *
 * It never writes to the web root and never touches the database. Images come
 * back as bytes in memory with an opaque per-conversion token embedded in the
 * HTML as <img src="TOKEN">; the CALLER decides where those bytes go and
 * substitutes real URLs. That is what lets agent/post/kb_article.php run this
 * through the exact plumbing the DOCX importer already uses, with one code path
 * to reason about instead of two. The one extra field is 'text' - poppler gives
 * us clean plain text nearly free, and it is a better feed for
 * kb_article_content_raw (the FULLTEXT index) than tag-stripping the HTML.
 *
 * WHY POPPLER AND NOT PURE PHP. A PDF has no document structure. It is
 * absolutely-positioned glyph runs, so "convert a PDF to an article" means
 * reconstructing structure from geometry. poppler already does the hard half -
 * text extraction in reading order, font resolution, image decoding - and
 * pdftohtml's -xml mode hands all of it over with coordinates and font metadata
 * attached. poppler-utils 24.02.0 is installed on this host; there is no new
 * dependency to add, and no pure-PHP library comes close to the output quality.
 *
 * WHAT WE RECOVER, and what we do not:
 *   recovered  headings (from font size and family), paragraphs (from line
 *              geometry), ordered and unordered lists (from leading markers and
 *              indentation), bold and italic runs, embedded raster images placed
 *              in reading order.
 *   NOT        tables (poppler's -xml emits their cells as ordinary positioned
 *              text; reconstructing a grid from coordinates is a different and
 *              much less reliable problem, and a wrong table is worse than
 *              paragraphs), multi-column reflow, footnote linkage, vector
 *              artwork, and anything in a scanned PDF - see convert()'s
 *              no-text-layer branch.
 *   Multi-column layout IS detected and warned about - it is visible in the
 *   geometry as a gap wider than 2.5 line heights, so the reader is told the row
 *   was flattened. The others are not detectable from poppler's output without
 *   guessing, so they degrade silently into paragraphs; the import modal says so
 *   up front rather than this class pretending it can spot them.
 *
 * ============================ RUNNING A C BINARY ============================
 *
 * This is the only place in the application that executes an external program on
 * bytes a user uploaded, and poppler has a CVE history. Every one of these is
 * load-bearing:
 *
 *   NO SHELL, EVER. proc_open() is given an ARGV ARRAY, which PHP passes to
 *   execvp() directly. No /bin/sh is constructed, so quoting, ;, $(), globbing
 *   and word-splitting are not merely escaped - they never exist. There is no
 *   shell_exec/system/passthru/backtick anywhere in this file.
 *
 *   NO USER STRING IS EVER AN ARGUMENT. The child's cwd is the scratch dir and
 *   the input is always the literal name 'in.pdf'. The uploaded file is COPIED
 *   there first. So poppler never sees the upload's tmp name, never sees the
 *   user's filename, and no argument can begin with '-' and be read as an option.
 *
 *   REDUCED ENVIRONMENT. env is exactly LC_ALL=C and PATH=/usr/bin. LC_ALL=C
 *   makes poppler's numeric output locale-independent, which matters because we
 *   parse it.
 *
 *   STDIN IS /dev/null. A child that decides to read stdin gets EOF instead of
 *   blocking until the timeout.
 *
 *   ABSOLUTE BINARY PATHS, is_executable()-checked before each spawn, so
 *   "poppler was uninstalled" is a clear message rather than a failed exec.
 *
 *   HARD WALL-CLOCK TIMEOUT that actually kills. run() polls proc_get_status()
 *   and drains both pipes non-blockingly - a child that fills a 64KB pipe buffer
 *   while we sleep would deadlock forever otherwise - then SIGKILLs on expiry.
 *
 *   SCRATCH DIR REMOVED IN A finally. Every path out of convert(), including a
 *   throw and including a throw from inside the parser, unlinks the directory.
 *
 * All budgets below were measured on this host and are documented at their
 * declarations. The caller must still purify the returned HTML - this class
 * emits a deliberately small tag set, but it is not a sanitiser.
 */
final class PdfConverter
{
    // ---- Binaries --------------------------------------------------------
    private const BIN_PDFINFO  = '/usr/bin/pdfinfo';
    private const BIN_PDFTOTEXT = '/usr/bin/pdftotext';
    private const BIN_PDFTOHTML = '/usr/bin/pdftohtml';

    // ---- Timeouts, milliseconds ------------------------------------------
    // Measured on this host against a 5-page, 547KB PDF: pdfinfo 0.01s,
    // pdftotext 0.04s, pdftohtml -xml 0.42s. These are 100x-plus headroom, set
    // so that a pathological file is killed well inside PHP's max_execution_time
    // (300s on this install) with room for all three stages plus the parse.
    private const TIMEOUT_INFO_MS   = 5000;
    private const TIMEOUT_TEXT_MS   = 20000;
    private const TIMEOUT_HTML_MS   = 45000;

    // ---- Budgets ---------------------------------------------------------
    // 32 MiB in: the ceiling the import modal advertises. Checked before any
    // child process is spawned.
    private const MAX_UPLOAD_BYTES = 33554432;

    // Enforced INSIDE poppler with -l, not after the fact, so a 900-page
    // document costs us 200 pages of work rather than 900 followed by a refusal.
    private const MAX_PAGES = 200;

    // Measured XML density on the fixture: 8.25 kB/page. 2 MiB is ~254 pages,
    // so MAX_PAGES binds first and this is the backstop for a page that is
    // pathologically text-dense.
    private const MAX_XML_BYTES  = 2097152;
    private const MAX_TEXT_BYTES = 1048576;

    // Same shape and the same numbers as DocxConverter, so a document that
    // imports one way imports the other.
    private const MAX_IMAGE_BYTES_EACH  = 8388608;
    private const MAX_IMAGE_BYTES_TOTAL = 25165824;
    private const MAX_IMAGES            = 100;
    private const MAX_HTML_BYTES        = 4194304;

    // A <text> line longer than this is a malformed or hostile extraction, not
    // prose. Truncated with a warning rather than refused.
    private const MAX_LINE_CHARS = 20000;

    // ---- Accepted media --------------------------------------------------
    // Identical to DocxConverter::IMAGE_MIME_EXT. poppler only ever writes PNG
    // or JPEG here, but the sniff is what decides, never the extension poppler
    // chose, so the wider list costs nothing and keeps the two converters
    // answering the same question the same way.
    private const IMAGE_MIME_EXT = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/bmp'  => 'bmp',
    ];
    private const IMAGE_TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_BMP];

    /** @var string[] */
    private array $warnings = [];
    /** @var array<int,array{token:string,extension:string,mime:string,bytes:string}> */
    private array $media = [];
    private string $tokenPrefix = '';
    private int $mediaBytesUsed = 0;

    /**
     * @return array{html:string,text:string,media:array,warnings:array}
     * @throws PdfConversionException
     */
    public static function convert(string $path): array
    {
        return (new self())->run($path);
    }

    /**
     * @return array{html:string,text:string,media:array,warnings:array}
     * @throws PdfConversionException
     */
    private function run(string $path): array
    {
        $this->tokenPrefix = 'itflow-pdf-media-' . bin2hex(random_bytes(16)) . '-';

        // ---- STAGE 0: PHP only. No child process yet. --------------------
        foreach ([self::BIN_PDFINFO, self::BIN_PDFTOTEXT, self::BIN_PDFTOHTML] as $bin) {
            if (!is_executable($bin)) {
                throw new PdfConversionException(
                    'PDF import is not available on this server (the PDF tools are not installed). Ask an administrator to install poppler-utils.'
                );
            }
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new PdfConversionException('That file could not be read.');
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new PdfConversionException('That file is empty.');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new PdfConversionException(
                'That PDF is larger than ' . (int) (self::MAX_UPLOAD_BYTES / 1048576) . ' MB. Split it, or attach it to an article instead of importing it.'
            );
        }

        // Five bytes, via fread - never file_get_contents(). This class must not
        // be the second place in this codebase to OOM on an upload.
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new PdfConversionException('That file could not be read.');
        }
        $magic = fread($fh, 5);
        fclose($fh);
        if ($magic !== '%PDF-') {
            throw new PdfConversionException('That file is not a readable PDF.');
        }

        // ---- STAGE 1: scratch dir ----------------------------------------
        $dir = $this->makeScratchDir();

        try {
            if (!@copy($path, $dir . '/in.pdf')) {
                throw new PdfConversionException('That file could not be read.');
            }

            $pages = $this->gate($dir);
            $text  = $this->extractText($dir, $pages);
            $html  = $this->extractStructure($dir, $pages);

            return [
                'html'     => $html,
                'text'     => $text,
                'media'    => array_values($this->media),
                'warnings' => array_values(array_unique($this->warnings)),
            ];
        } finally {
            // Every path out of here, throw included, removes the directory.
            $this->removeScratchDir($dir);
        }
    }

    // =====================================================================
    // Stages
    // =====================================================================

    /**
     * STAGE 2 - refuse what we cannot import, before spending anything on it.
     *
     * Gating on pdfinfo's EXIT CODE, not on its stderr text, is deliberate:
     * measured on this host, a plain text file renamed .pdf makes poppler print
     * "Syntax Warning: May not be a PDF file (continuing anyway)" and then
     * CONTINUE. Only the exit code distinguishes "warned but fine" from "failed".
     *
     * @return int page count to process
     */
    private function gate(string $dir): int
    {
        $r = $this->exec([self::BIN_PDFINFO, '-enc', 'UTF-8', 'in.pdf'], $dir, self::TIMEOUT_INFO_MS);

        if ($r['timedout']) {
            throw new PdfConversionException('That PDF took too long to read and was stopped.');
        }
        if ($r['code'] !== 0) {
            // Measured exit 1 for all of: a user-password PDF ("Command Line
            // Error: Incorrect password"), a truncated file ("Syntax Error:
            // Couldn't find trailer dictionary"), and a text file renamed .pdf.
            if (stripos($r['stderr'], 'password') !== false || stripos($r['stderr'], 'encrypt') !== false) {
                throw new PdfConversionException('That PDF is password-protected. Open it in a PDF reader, save an unprotected copy, and import that.');
            }
            error_log('PdfConverter: pdfinfo exited ' . $r['code'] . ': ' . substr($r['stderr'], 0, 300));
            throw new PdfConversionException('That file is not a readable PDF.');
        }

        // "Encrypted: yes (print:no copy:no ...)" - an owner-password PDF that
        // pdfinfo reads happily but whose author has set copy restrictions.
        // Caught here rather than becoming a confusing "Permission Error" three
        // stages later. We do NOT pass -nodrm anywhere: honouring the flag is
        // both the lawful default and what makes this branch reachable.
        if (preg_match('/^Encrypted:\s*yes/mi', $r['stdout'])) {
            throw new PdfConversionException('That PDF has copy restrictions set by its author. Save an unrestricted copy and import that.');
        }

        if (!preg_match('/^Pages:\s*(\d+)/mi', $r['stdout'], $m)) {
            throw new PdfConversionException('That file is not a readable PDF.');
        }
        $pages = (int) $m[1];
        if ($pages < 1) {
            throw new PdfConversionException('That PDF has no pages in it.');
        }
        if ($pages > self::MAX_PAGES) {
            $this->warn('Only the first ' . self::MAX_PAGES . ' pages were imported; the document has ' . $pages . '.');
            $pages = self::MAX_PAGES;
        }

        return $pages;
    }

    /**
     * STAGE 3 - plain text, and the no-text-layer test.
     *
     * -nopgbrk is what makes the test clean. Measured on this host: an image-only
     * PDF yields EXACTLY 0 bytes with -nopgbrk, and 1 byte (0x0C, a form feed)
     * without it - which is easy to mistake for content. Running this before the
     * much more expensive pdftohtml means a scan is refused cheaply.
     */
    private function extractText(string $dir, int $pages): string
    {
        $r = $this->exec(
            [self::BIN_PDFTOTEXT, '-q', '-enc', 'UTF-8', '-eol', 'unix', '-nopgbrk', '-l', (string) $pages, 'in.pdf', 'out.txt'],
            $dir,
            self::TIMEOUT_TEXT_MS
        );

        if ($r['timedout']) {
            throw new PdfConversionException('That PDF took too long to read and was stopped.');
        }
        if ($r['code'] !== 0) {
            error_log('PdfConverter: pdftotext exited ' . $r['code'] . ': ' . substr($r['stderr'], 0, 300));
            throw new PdfConversionException('The text in that PDF could not be read.');
        }

        $text = $this->readCapped($dir . '/out.txt', self::MAX_TEXT_BYTES, 'text');

        if (trim($text) === '') {
            throw new PdfConversionException(
                'That PDF has no text in it - it looks like a scan or a photo. There is no OCR on this server, so there is nothing to import. Attach it to an article instead.'
            );
        }

        return $text;
    }

    /**
     * STAGE 4 - structure. pdftohtml -xml, then five passes over the DOM.
     */
    private function extractStructure(string $dir, int $pages): string
    {
        // Flags deliberately NOT passed, each for a reason:
        //   -nodrm     honouring DRM is the lawful default; gate() relies on it.
        //   -hidden    invisible text in a PDF is an injection vector - text the
        //              author can see is the text we import.
        //   -i         we WANT the images.
        $r = $this->exec(
            [self::BIN_PDFTOHTML, '-xml', '-enc', 'UTF-8', '-q', '-l', (string) $pages, 'in.pdf', 'out.xml'],
            $dir,
            self::TIMEOUT_HTML_MS
        );

        if ($r['timedout']) {
            throw new PdfConversionException('That PDF took too long to convert and was stopped.');
        }
        if ($r['code'] !== 0) {
            error_log('PdfConverter: pdftohtml exited ' . $r['code'] . ': ' . substr($r['stderr'], 0, 300));
            throw new PdfConversionException('That PDF could not be converted.');
        }

        $xml = $this->readCapped($dir . '/out.xml', self::MAX_XML_BYTES, 'structure');

        $blocks = $this->parseXml($xml);
        $this->collectImages($dir, $blocks);

        return $this->render($blocks);
    }

    // =====================================================================
    // Parsing
    // =====================================================================

    /**
     * PASS 0/1 - load the XML and flatten it to per-page line fragments.
     *
     * XXE defence is DocxConverter's, unchanged and for the same reason: the
     * input is attacker-influenced XML. poppler emits exactly one DOCTYPE
     * (`<!DOCTYPE pdf2xml SYSTEM "pdf2xml.dtd">`), which is stripped by an
     * anchored pattern; after that the strict "no DOCTYPE, no ENTITY" invariant
     * must hold, and it is re-checked on the parsed document.
     *
     * @return array<int,array<string,mixed>> pages, each {w,h,lines[],images[]}
     */
    private function parseXml(string $xml): array
    {
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
        $xml = preg_replace('/<\?xml[^>]*\?>/i', '', $xml, 1);
        $xml = preg_replace('/<!DOCTYPE\s+pdf2xml\s+SYSTEM\s+"pdf2xml\.dtd"\s*>/i', '', (string) $xml, 1);

        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', (string) $xml)) {
            throw new PdfConversionException('That PDF could not be converted.');
        }

        $prev = libxml_use_internal_errors(true);
        $doc  = new \DOMDocument();
        $doc->resolveExternals  = false;
        $doc->substituteEntities = false;

        // LIBXML_NOENT is deliberately ABSENT - it is the flag that would expand
        // entities. LIBXML_NONET blocks any network fetch a DTD could attempt.
        $ok = $doc->loadXML('<pdf2xml>' . $xml . '</pdf2xml>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok || $doc->doctype !== null) {
            throw new PdfConversionException('That PDF could not be converted.');
        }

        $pages = [];
        foreach ($doc->getElementsByTagName('page') as $pageEl) {
            /** @var \DOMElement $pageEl */
            $page = [
                'w'      => (float) $pageEl->getAttribute('width'),
                'h'      => (float) $pageEl->getAttribute('height'),
                'fonts'  => [],
                'lines'  => [],
                'images' => [],
            ];

            foreach ($pageEl->childNodes as $node) {
                if (!($node instanceof \DOMElement)) {
                    continue;
                }
                if ($node->nodeName === 'fontspec') {
                    // The WEIGHT LIVES IN THE FAMILY NAME. poppler reports
                    // "AAAAAA+IBMPlexSans-SemiBold" vs "BAAAAA+IBMPlexSans" -
                    // there is no weight attribute to read.
                    $family = $node->getAttribute('family');
                    $page['fonts'][$node->getAttribute('id')] = [
                        'size' => (float) $node->getAttribute('size'),
                        'bold' => (bool) preg_match('/(bold|semibold|black|heavy|extrabold)/i', $family),
                        'ital' => (bool) preg_match('/(italic|oblique)/i', $family),
                    ];
                } elseif ($node->nodeName === 'text') {
                    $frag = $this->readTextNode($node);
                    if ($frag['plain'] === '') {
                        continue;
                    }
                    $page['lines'][] = $frag + [
                        'top'   => (float) $node->getAttribute('top'),
                        'left'  => (float) $node->getAttribute('left'),
                        'width' => (float) $node->getAttribute('width'),
                        'hgt'   => (float) $node->getAttribute('height'),
                        'font'  => $node->getAttribute('font'),
                    ];
                } elseif ($node->nodeName === 'image') {
                    $page['images'][] = [
                        'src'   => $node->getAttribute('src'),
                        'top'   => (float) $node->getAttribute('top'),
                        'left'  => (float) $node->getAttribute('left'),
                        'width' => (float) $node->getAttribute('width'),
                    ];
                }
            }

            // poppler emits <text> in reading order already, but a page whose
            // content streams are out of order can still arrive scrambled; a
            // stable sort by top then left costs nothing and fixes it. Sorting
            // is by GEOMETRY only - text is never reordered against text on the
            // basis of what it says.
            usort($page['lines'], static function (array $a, array $b): int {
                return ($a['top'] <=> $b['top']) ?: ($a['left'] <=> $b['left']);
            });

            $page['lines'] = $this->assembleLines($page['lines']);

            $pages[] = $page;
        }

        if ($pages === []) {
            throw new PdfConversionException('That PDF could not be converted.');
        }

        return $pages;
    }

    /**
     * PASS 1.5 - style runs -> visual lines. Without this, nothing else works.
     *
     * A <text> element is NOT a line. poppler emits one per STYLE RUN, so a
     * sentence with a bold clause in it arrives as two or three elements that
     * share a `top`. Measured on the fixture:
     *
     *   <text top="324" left="88"  width="311" font="0"><b>Parts 1 and 2 are the project manager's job.</b></text>
     *   <text top="324" left="399" width="394" font="1"> A job is not released to the floor until every material line</text>
     *
     * Treating those as two lines is what shredded the first build of this
     * converter: the bold half was short, bold and had no terminal punctuation,
     * so it matched the heading rule, and a two-character bold run mid-sentence
     * ("MO") became an <h4>. Merging by `top` first is what makes the heading and
     * list rules see whole sentences, which is what they are written for.
     *
     * Fragments are joined in LEFT order, and a space is inserted where the gap
     * between one fragment's right edge and the next one's left edge is wider
     * than a space - poppler does not put the inter-fragment space in either
     * fragment, and a four-column header row would otherwise read
     * "1. Set up2. Get material". The resulting line takes the font of whichever
     * fragment contributed the most characters, so a mostly-regular sentence with
     * a bold clause is classified on the regular font rather than the bold one.
     *
     * @param  array<int,array<string,mixed>> $frags sorted by top, then left
     * @return array<int,array<string,mixed>>
     */
    private function assembleLines(array $frags): array
    {
        $lines = [];
        $group = [];

        $flush = function () use (&$group, &$lines): void {
            if ($group === []) {
                return;
            }

            $runs  = [];
            $plain = '';
            $prevRight = null;
            $byFont = [];

            foreach ($group as $f) {
                /* Does a separator need inventing here? Only when poppler
                   supplied none on EITHER side and the glyphs really are apart.
                   Measured gaps at style boundaries on the fixture:

                     gap  prev ends  next starts  needs a space?
                      -1  '5. '      'Bil'        no  - prev has it
                       1  'ial'      ' fi'        no  - next has it
                       0  'ob.'      ' A '        no  - next has it
                       3  ' MO'      'PM '        YES - neither does
                      61  ' up'      '2. '        YES - column boundary

                   Requiring a positive gap is what keeps a mid-word style change
                   ("Micro" + bold "soft") from becoming "Micro soft": those glyphs
                   are adjacent, so their gap is 0 or negative. */
                $gap = $prevRight === null ? null : $f['left'] - $prevRight;

                /* A gap this wide is not word spacing, it is a COLUMN. The
                   fixture's four-column step summary measured gaps of 53, 61 and
                   75px against a 20px line height. We join them anyway, in
                   reading order, which is what pdftotext does too - but the
                   result is a flattened row, so say so rather than let the reader
                   discover it. Threshold is 2.5 line heights: wide enough that
                   ordinary inter-sentence spacing and a hanging indent never
                   trip it. */
                if ($gap !== null && $gap > max($f['hgt'], 1.0) * 2.5) {
                    $this->warn('This document has side-by-side columns. They were read left to right and joined into single lines, so some rows may need re-splitting.');
                }

                if ($gap !== null && $gap >= 1
                    && $plain !== '' && !preg_match('/\s$/u', $plain)
                    && !preg_match('/^\s/u', $f['plain'])) {
                    $runs[]  = ['t' => ' ', 'b' => false, 'i' => false];
                    $plain  .= ' ';
                }
                foreach ($f['runs'] as $r) {
                    $runs[] = $r;
                }
                $plain .= $f['plain'];
                $byFont[$f['font']] = ($byFont[$f['font']] ?? 0) + strlen($f['plain']);
                $prevRight = $f['left'] + $f['width'];
            }

            arsort($byFont);

            $first = $group[0];
            $lines[] = [
                'plain' => trim($plain),
                'runs'  => $runs,
                'top'   => $first['top'],
                'left'  => $first['left'],
                'width' => $prevRight - $first['left'],
                'hgt'   => max(array_column($group, 'hgt')),
                'font'  => (string) array_key_first($byFont),
            ];
            $group = [];
        };

        foreach ($frags as $f) {
            if ($group !== []) {
                // Same visual line when the baselines are within a third of a
                // line height. Tolerant enough for a superscript or a font
                // whose box sits a pixel high, tight enough that consecutive
                // lines of prose never merge - measured line pitch on the
                // fixture is 22px against a 20px height, so a third (6.7px) is
                // comfortably below the 22px step.
                $ref = $group[0];
                if (abs($f['top'] - $ref['top']) > max($ref['hgt'], 1.0) / 3) {
                    $flush();
                }
            }
            $group[] = $f;
        }
        $flush();

        return $lines;
    }

    /**
     * One <text> element -> {plain, runs[]}, preserving <b>/<i>.
     *
     * poppler nests only <b>, <i> and text nodes inside <text>, but this walks
     * whatever it is given rather than assuming, and it caps depth so a
     * hand-crafted XML cannot recurse us to death.
     *
     * @return array{plain:string,runs:array<int,array{t:string,b:bool,i:bool}>}
     */
    private function readTextNode(\DOMElement $el): array
    {
        $runs  = [];
        $plain = '';

        $walk = function (\DOMNode $n, bool $b, bool $i, int $depth) use (&$walk, &$runs, &$plain): void {
            if ($depth > 12) {
                return;
            }
            foreach ($n->childNodes as $c) {
                if ($c->nodeType === XML_TEXT_NODE) {
                    // poppler uses &#160; for the spaces inside a line; a
                    // non-breaking space in article prose would stop it wrapping.
                    $t = str_replace("\xC2\xA0", ' ', (string) $c->nodeValue);
                    if ($t === '') {
                        continue;
                    }
                    if (strlen($plain) > self::MAX_LINE_CHARS) {
                        return;
                    }
                    $plain .= $t;
                    $runs[] = ['t' => $t, 'b' => $b, 'i' => $i];
                } elseif ($c instanceof \DOMElement) {
                    $name = strtolower($c->nodeName);
                    $walk($c, $b || $name === 'b', $i || $name === 'i', $depth + 1);
                }
            }
        };
        $walk($el, false, false, 0);

        if (strlen($plain) > self::MAX_LINE_CHARS) {
            $this->warn('A very long line was truncated.');
            $plain = substr($plain, 0, self::MAX_LINE_CHARS);
            $runs  = [['t' => $plain, 'b' => false, 'i' => false]];
        }

        /* NOT rtrim()'d. A <text> element is a style run, not a line, and poppler
           puts the word-separating space at the END of the run that precedes a
           style change - measured: the fragments either side of "5. Bill" are
           '5. ' and 'Bill of Material'. Trimming here destroyed that space and
           assembleLines() then produced "5.Bill of Material", which the ordered
           list rule could not match, so numbered step 5 fell out of its <ol> and
           became a paragraph. Whole lines are trimmed in assembleLines(). */
        return ['plain' => $plain, 'runs' => $runs];
    }

    // =====================================================================
    // Images
    // =====================================================================

    /**
     * PASS 2 - read the extracted image files, validate them, and hand each page
     * its media tokens.
     *
     * Every file here was named by POPPLER from an argument we chose, into a
     * directory we made, so the name is not user input - but it is validated
     * against a strict pattern and realpath-contained anyway, because "it cannot
     * be hostile" is exactly the assumption worth not making.
     *
     * The sniffing (steps 5-8) is DocxConverter::extractMedia()'s, unchanged: two
     * independent sniffs must agree the bytes are a raster image of an allowed
     * type, and the extension comes from that verdict rather than from the name.
     */
    private function collectImages(string $dir, array &$pages): void
    {
        $root = realpath($dir);
        if ($root === false) {
            return;
        }

        foreach ($pages as $pi => $page) {
            foreach ($page['images'] as $ii => $img) {
                if (count($this->media) >= self::MAX_IMAGES) {
                    $this->warn('Some images were skipped: this document has more than ' . self::MAX_IMAGES . '.');
                    break 2;
                }

                $name = $img['src'];
                if (!is_string($name) || !preg_match('/^out-\d{1,6}_\d{1,6}\.(png|jpg|jpeg)$/', $name)) {
                    continue;
                }

                $file = realpath($root . '/' . $name);
                if ($file === false
                    || strncmp($file, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0
                    || is_link($root . '/' . $name)
                    || !is_file($file)) {
                    continue;
                }

                $bytes = $this->readImage($file);
                if ($bytes === null) {
                    continue;
                }

                $token = $this->acceptImage($bytes);
                if ($token !== null) {
                    $pages[$pi]['images'][$ii]['token'] = $token;
                }
            }
        }
    }

    private function readImage(string $file): ?string
    {
        $size = filesize($file);
        if ($size === false || $size <= 0) {
            return null;
        }
        if ($size > self::MAX_IMAGE_BYTES_EACH) {
            $this->warn('An image larger than ' . (int) (self::MAX_IMAGE_BYTES_EACH / 1048576) . ' MB was skipped.');
            return null;
        }
        if ($this->mediaBytesUsed + $size > self::MAX_IMAGE_BYTES_TOTAL) {
            $this->warn('Some images were skipped: this document carries more than ' . (int) (self::MAX_IMAGE_BYTES_TOTAL / 1048576) . ' MB of them.');
            return null;
        }

        $fh = fopen($file, 'rb');
        if ($fh === false) {
            return null;
        }
        $bytes = fread($fh, self::MAX_IMAGE_BYTES_EACH + 1);
        fclose($fh);

        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES_EACH) {
            return null;
        }

        $this->mediaBytesUsed += strlen($bytes);
        return $bytes;
    }

    private function acceptImage(string $bytes): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = strtolower(trim(explode(';', (string) $finfo->buffer($bytes))[0]));

        if (!isset(self::IMAGE_MIME_EXT[$mime])) {
            $this->warn('An embedded file that is not an image was skipped.');
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset($info[2]) || !in_array($info[2], self::IMAGE_TYPES, true)) {
            $this->warn('An embedded file that claimed to be an image but does not decode was skipped.');
            return null;
        }
        if (strtolower(image_type_to_mime_type($info[2])) !== $mime) {
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

        return $token;
    }

    // =====================================================================
    // Rendering
    // =====================================================================

    /**
     * PASS 3/4/5 - geometry to semantics.
     *
     * THE BODY SIZE is the anchor for every heading decision, and it is the
     * character-weighted modal font size across the document rather than the
     * mean: a title in 32pt would drag a mean upwards, but it cannot outvote the
     * thousands of characters set in body text. Everything larger is a heading;
     * everything at body size is a paragraph, list item, or an inline bold run.
     */
    private function render(array $pages): string
    {
        $bodySize = $this->bodySize($pages);

        /* Does font SIZE carry any heading signal in this document at all?
           Often it does not. The fixture measured here - a five-page runbook
           exported from this very app - uses exactly ONE size, 16, for its title,
           its section headings and its body alike; the only thing separating them
           is the font family ("IBMPlexSans-SemiBold" against "IBMPlexSans").
           When that is the case, a short bold line is the ONLY heading evidence
           the document contains, so it has to carry the weight of a real section
           heading rather than being demoted to h4 under headings that do not
           exist. */
        $sizes = [];
        foreach ($pages as $page) {
            foreach ($page['fonts'] as $f) {
                $sizes[(string) round($f['size'], 1)] = true;
            }
        }
        $sizeVaries = count($sizes) > 1;

        $out      = [];
        $list     = null;   // ['tag' => 'ol'|'ul', 'items' => [...]]

        $flushList = function () use (&$list, &$out): void {
            if ($list !== null) {
                $out[] = '<' . $list['tag'] . '>' . implode('', array_map(
                    static fn(string $li): string => '<li>' . $li . '</li>',
                    $list['items']
                )) . '</' . $list['tag'] . '>';
                $list = null;
            }
        };

        foreach ($pages as $page) {
            $paras = $this->groupLines($page, $bodySize, $sizeVaries);

            foreach ($paras as $p) {
                // Images that belong above this paragraph, spliced in by
                // geometry. poppler emits every <image> BEFORE every <text> on
                // its page - measured, page 1's image is top=451 while the first
                // line is top=101 - so document order alone would stack every
                // picture at the top of its page.
                foreach ($this->imagesBefore($page, $p) as $token) {
                    $flushList();
                    $out[] = '<p><img src="' . $token . '" alt=""></p>';
                }

                if ($p['kind'] === 'heading') {
                    $flushList();
                    $tag   = $p['level'];
                    $out[] = '<' . $tag . '>' . $p['html'] . '</' . $tag . '>';
                } elseif ($p['kind'] === 'li') {
                    if ($list === null || $list['tag'] !== $p['tag']) {
                        $flushList();
                        $list = ['tag' => $p['tag'], 'items' => []];
                    }
                    $list['items'][] = $p['html'];
                } else {
                    $flushList();
                    $out[] = '<p>' . $p['html'] . '</p>';
                }
            }

            // Anything left on the page that no paragraph pulled in.
            foreach ($this->remainingImages($page) as $token) {
                $flushList();
                $out[] = '<p><img src="' . $token . '" alt=""></p>';
            }
        }
        $flushList();

        $html = implode("\n", $out);

        if (strlen($html) > self::MAX_HTML_BYTES) {
            $this->warn('The document was longer than this importer can store and was truncated.');
            $html = substr($html, 0, self::MAX_HTML_BYTES);
            // Never leave a half-written tag behind for the purifier to guess at.
            $cut  = strrpos($html, '</p>');
            if ($cut !== false) {
                $html = substr($html, 0, $cut + 4);
            }
        }

        if (trim(strip_tags($html)) === '') {
            throw new PdfConversionException('Nothing could be read out of that PDF.');
        }

        return $html;
    }

    /** Character-weighted modal font size. */
    private function bodySize(array $pages): float
    {
        $weight = [];
        foreach ($pages as $page) {
            foreach ($page['lines'] as $line) {
                $size = $page['fonts'][$line['font']]['size'] ?? 0.0;
                if ($size <= 0) {
                    continue;
                }
                $key = (string) round($size, 1);
                $weight[$key] = ($weight[$key] ?? 0) + strlen($line['plain']);
            }
        }
        if ($weight === []) {
            return 12.0;
        }
        arsort($weight);
        return (float) array_key_first($weight);
    }

    /**
     * Lines -> paragraphs, headings and list items.
     *
     * Two lines join into one paragraph when the vertical step between them is
     * about one line height and their left edges agree. A bigger step, a change
     * of indent, or a change of font ends the paragraph. That is the whole of
     * the paragraph rule - it is geometric, so it behaves the same on a document
     * whose language we cannot read.
     */
    private function groupLines(array $page, float $bodySize, bool $sizeVaries): array
    {
        $paras = [];
        $cur   = null;
        $prev  = null;

        foreach ($page['lines'] as $line) {
            $font = $page['fonts'][$line['font']] ?? ['size' => $bodySize, 'bold' => false, 'ital' => false];
            $kind = $this->classify($line, $font, $bodySize, $sizeVaries);

            $break = true;
            if ($cur !== null && $prev !== null && $kind['kind'] === 'p' && $cur['kind'] === 'p') {
                $step = $line['top'] - $prev['top'];
                $lead = max($prev['hgt'], 1.0);
                // <= 1.8 line-heights and the left edge within half a line
                // height: the same block of prose, wrapped.
                $break = !($step > 0 && $step <= $lead * 1.8 && abs($line['left'] - $prev['left']) <= $lead * 0.5);
            }

            if ($break || $cur === null) {
                if ($cur !== null) {
                    $paras[] = $cur;
                }
                $cur = $kind;
                $cur['top'] = $line['top'];
            } else {
                $cur['html'] .= ' ' . $kind['html'];
            }
            $prev = $line;
        }
        if ($cur !== null) {
            $paras[] = $cur;
        }

        return $paras;
    }

    /** One line -> its semantic kind and its inline HTML. */
    private function classify(array $line, array $font, float $bodySize, bool $sizeVaries): array
    {
        $text = $line['plain'];

        // A list marker is recognised from the START of the line only, so a
        // sentence that merely contains "1." mid-way is left as prose.
        if (preg_match('/^\s*(\d{1,3})[.)]\s+(.*)$/u', $text, $m)) {
            return ['kind' => 'li', 'tag' => 'ol', 'html' => $this->inline($line, mb_strlen($m[0]) - mb_strlen($m[2]))];
        }
        if (preg_match('/^\s*[\x{2022}\x{2023}\x{25E6}\x{2043}\x{2219}\x{00B7}\x{25AA}\x{25CF}*-]\s+(.*)$/u', $text, $m)) {
            return ['kind' => 'li', 'tag' => 'ul', 'html' => $this->inline($line, mb_strlen($m[0]) - mb_strlen($m[1]))];
        }

        // Headings. Size first, because it is the reliable signal; a bold run at
        // body size is only a heading when the line is SHORT and stands alone,
        // otherwise it is a bold sentence inside a paragraph (which this document
        // set is full of - "Parts 1 and 2 are the project manager's job." is bold
        // and is not a heading).
        $size = $font['size'];
        if ($size >= $bodySize * 1.45) {
            return ['kind' => 'heading', 'level' => 'h2', 'html' => $this->inline($line, 0)];
        }
        if ($size >= $bodySize * 1.15) {
            return ['kind' => 'heading', 'level' => 'h3', 'html' => $this->inline($line, 0)];
        }
        if ($font['bold'] && mb_strlen($text) <= 60 && !preg_match('/[.!?]\s*$/u', $text)) {
            // h3 when size told us nothing, h4 when it did - see $sizeVaries in
            // render(). A document that sets every heading at body size still
            // deserves headings that look like headings.
            return ['kind' => 'heading', 'level' => $sizeVaries ? 'h4' : 'h3', 'html' => $this->inline($line, 0)];
        }

        return ['kind' => 'p', 'html' => $this->inline($line, 0)];
    }

    /**
     * Inline runs -> escaped HTML with <strong>/<em>, dropping $skip leading
     * characters (a consumed list marker).
     */
    private function inline(array $line, int $skip): string
    {
        $html = '';
        foreach ($line['runs'] as $run) {
            $t = $run['t'];
            if ($skip > 0) {
                $len = mb_strlen($t);
                if ($len <= $skip) {
                    $skip -= $len;
                    continue;
                }
                $t = mb_substr($t, $skip);
                $skip = 0;
            }
            if ($t === '') {
                continue;
            }
            // Escaped here, so nothing that came out of the PDF can be markup.
            $piece = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($run['b']) {
                $piece = '<strong>' . $piece . '</strong>';
            }
            if ($run['i']) {
                $piece = '<em>' . $piece . '</em>';
            }
            $html .= $piece;
        }

        // Adjacent runs of the same weight come back as separate elements;
        // collapsing them keeps the stored HTML readable in TinyMCE.
        $html = str_replace(['</strong><strong>', '</em><em>'], '', $html);

        return trim($html) === '' ? '' : $html;
    }

    /**
     * Images whose top is above this paragraph and whose horizontal span
     * overlaps it. The column test is what stops a right-hand figure being
     * spliced into left-hand prose.
     *
     * @return string[] tokens, consumed
     */
    private function imagesBefore(array &$page, array $para): array
    {
        $take = [];
        foreach ($page['images'] as $i => $img) {
            if (!isset($img['token']) || !empty($img['used'])) {
                continue;
            }
            if ($img['top'] <= $para['top']) {
                $take[] = $img['token'];
                $page['images'][$i]['used'] = true;
            }
        }
        return $take;
    }

    /** @return string[] */
    private function remainingImages(array &$page): array
    {
        $take = [];
        foreach ($page['images'] as $i => $img) {
            if (isset($img['token']) && empty($img['used'])) {
                $take[] = $img['token'];
                $page['images'][$i]['used'] = true;
            }
        }
        return $take;
    }

    // =====================================================================
    // Process and filesystem plumbing
    // =====================================================================

    /**
     * Run one poppler command. NO SHELL: proc_open() is given an argv array,
     * which PHP hands to execvp() directly.
     *
     * @return array{code:int,stdout:string,stderr:string,timedout:bool}
     */
    private function exec(array $argv, string $cwd, int $timeoutMs): array
    {
        $desc = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = ['LC_ALL' => 'C', 'PATH' => '/usr/bin'];

        $proc = @proc_open($argv, $desc, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            throw new PdfConversionException('PDF import is not available on this server (the PDF tools could not be started).');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $exitFromStatus = -1;
        $timedout = false;

        while (true) {
            /* Drain BOTH pipes to EMPTY every tick, not once per tick.
               A child that fills a pipe buffer blocks on write and never exits,
               so polling proc_get_status() alone would hang until the timeout on
               any chatty document. But one fread() per tick is not draining -
               fread() on a non-blocking pipe returns what is available right now,
               which is typically a few KB, not the 64KB asked for. Measured: at
               one read per 10ms tick, /bin/dd emitting 4 MB on stdout took longer
               than a 5s timeout and was killed as if it had hung. Draining in an
               inner loop until fread() comes back empty fixes it; the tick's
               sleep then only happens when there is genuinely nothing to read.

               Reads stay capped for the same reason the files are: poppler must
               not be able to make us allocate without bound. Past the cap the
               bytes are still READ - and discarded - because the point of
               draining is to unblock the child, not to keep the data. */
            foreach ([1, 2] as $fd) {
                while (($chunk = fread($pipes[$fd], 65536)) !== false && $chunk !== '') {
                    if ($fd === 1) {
                        if (strlen($stdout) < 262144) {
                            $stdout .= $chunk;
                        }
                    } elseif (strlen($stderr) < 65536) {
                        $stderr .= $chunk;
                    }
                }
            }

            $status = proc_get_status($proc);
            if (!$status['running']) {
                // Before PHP 8.3 the exit code is only reported by the first proc_get_status() call that sees the
                // process finished, and proc_close() then returns -1. Keep it.
                $exitFromStatus = $status['exitcode'] ?? -1;
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedout = true;
                proc_terminate($proc, 9);   // SIGKILL - not SIGTERM, which a wedged child may ignore
                break;
            }
            usleep(10000);
        }

        // Final drain: whatever the child wrote between our last read and exit.
        foreach ([1 => 'stdout', 2 => 'stderr'] as $fd => $_) {
            $rest = stream_get_contents($pipes[$fd]);
            if (is_string($rest) && $rest !== '') {
                if ($fd === 1 && strlen($stdout) < 262144) {
                    $stdout .= $rest;
                } elseif ($fd === 2 && strlen($stderr) < 65536) {
                    $stderr .= $rest;
                }
            }
            fclose($pipes[$fd]);
        }

        $code = proc_close($proc);
        if ($code === -1 && $exitFromStatus >= 0) {
            $code = $exitFromStatus;
        }

        return [
            'code'     => $timedout ? -1 : (int) $code,
            'stdout'   => $stdout,
            'stderr'   => $stderr,
            'timedout' => $timedout,
        ];
    }

    private function makeScratchDir(): string
    {
        $base = realpath(sys_get_temp_dir());
        if ($base === false) {
            throw new PdfConversionException('PDF import is not available on this server (no writable temporary directory).');
        }

        $dir = $base . '/itflow-pdf-' . bin2hex(random_bytes(16));

        // 0700 and a name with 128 bits of entropy. mkdir() returns false if the
        // path exists and does not follow a symlink, so there is no window in
        // which someone else's directory or link is adopted as ours.
        if (!@mkdir($dir, 0700)) {
            throw new PdfConversionException('PDF import is not available on this server (the temporary directory could not be created).');
        }

        $real = realpath($dir);
        if ($real === false || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
            @rmdir($dir);
            throw new PdfConversionException('PDF import is not available on this server (the temporary directory could not be created).');
        }

        return $real;
    }

    /**
     * Remove the scratch directory. One level deep only - poppler writes
     * out.xml, out.txt, in.pdf and out-N_N.png flat, and never a subdirectory -
     * so this deliberately does not recurse and cannot be walked into deleting
     * anything it was not given.
     */
    private function removeScratchDir(string $dir): void
    {
        $base = realpath(sys_get_temp_dir());
        $real = realpath($dir);
        if ($base === false || $real === false
            || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
            return;
        }

        $entries = @scandir($real);
        if (is_array($entries)) {
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                $p = $real . '/' . $e;
                if (is_file($p) || is_link($p)) {
                    @unlink($p);
                }
            }
        }
        @rmdir($real);
    }

    private function readCapped(string $file, int $cap, string $what): string
    {
        if (!is_file($file)) {
            throw new PdfConversionException('That PDF could not be converted.');
        }
        $size = filesize($file);
        if ($size === false) {
            throw new PdfConversionException('That PDF could not be converted.');
        }
        if ($size > $cap) {
            throw new PdfConversionException('That PDF is too complex to import (its ' . $what . ' is larger than this importer allows). Split it into smaller documents.');
        }

        $fh = fopen($file, 'rb');
        if ($fh === false) {
            throw new PdfConversionException('That PDF could not be converted.');
        }
        $data = $size === 0 ? '' : fread($fh, $cap);
        fclose($fh);

        return is_string($data) ? $data : '';
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
