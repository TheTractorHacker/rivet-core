<?php

declare(strict_types=1);

namespace RivetCore\Panel;

use RivetCore\Rmm\Http\ApiError;

/**
 * Validates the attachments of a ticket or chat post. Nothing the client says is trusted:
 *
 *  - the extension must be on the allow-list ({@see PanelLimits::$allowedExtensions}, which can only narrow the built-in table) AND no dot-separated part of the filename may be
 *    a known executable/script/markup extension (`report.exe.png` is refused),
 *  - the declared content type must be the one the extension maps to,
 *  - the bytes must start with the format's magic number (text types: valid UTF-8, no control bytes, no `#!` shebang, no HTML/script markup near the start),
 *  - executables are recognised by content too (MZ, ELF, Mach-O, shebang, ZIP/OLE containers under a document extension), whatever the name says,
 *  - the stored filename is a sanitised basename; the original is never used as a path (rc.38: a Windows device name such as `CON.txt` and a name that starts with `-` are refused),
 *  - a txt or log file must not start with a spreadsheet formula character (`=`, `+`, `-`, `@`, tab or carriage return, after an optional byte-order mark); in a CSV no cell
 *    may start with one (after white space and an optional quote) unless the whole cell is a plain number, read quote-aware with every separator a spreadsheet may use
 *    ({@see self::csvOk()}, CORE-DS-33 / CORE-API-1 / CORE-DATA-1); the whole text, not only its start, is searched for markup (security review RC36 L5).
 *    Active content inside a PNG/GIF/PDF is not scanned: the gateway must store the bytes outside the web root and serve them as a download with
 *    `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff` (docs/panel/PANEL_API.md, "Serving attachments").
 *
 * @api
 */
final class PanelAttachments
{
    /** ext => [canonical content type, accepted declared types, kind] */
    private const TYPES = [
        'png' => ['image/png', ['image/png'], 'png'],
        'jpg' => ['image/jpeg', ['image/jpeg', 'image/jpg'], 'jpeg'],
        'jpeg' => ['image/jpeg', ['image/jpeg', 'image/jpg'], 'jpeg'],
        'gif' => ['image/gif', ['image/gif'], 'gif'],
        'webp' => ['image/webp', ['image/webp'], 'webp'],
        'pdf' => ['application/pdf', ['application/pdf'], 'pdf'],
        'txt' => ['text/plain', ['text/plain'], 'text'],
        'log' => ['text/plain', ['text/plain'], 'text'],
        'csv' => ['text/csv', ['text/csv', 'text/plain', 'application/csv'], 'csv'],
    ];

    /** Refused in ANY dot-separated part of a filename. */
    private const DANGEROUS = ['exe', 'dll', 'sys', 'bat', 'cmd', 'com', 'scr', 'pif', 'msi', 'msp', 'mst', 'ps1', 'psm1', 'psd1', 'vbs', 'vbe', 'js', 'jse', 'mjs', 'wsf', 'wsh', 'hta',
        'jar', 'class', 'sh', 'bash', 'zsh', 'csh', 'ksh', 'py', 'pyc', 'pl', 'rb', 'php', 'phtml', 'phar', 'asp', 'aspx', 'jsp', 'cgi', 'lnk', 'url', 'reg', 'cpl', 'msc', 'gadget',
        'iso', 'img', 'vhd', 'vhdx', 'apk', 'app', 'deb', 'rpm', 'dmg', 'pkg', 'bin', 'elf', 'so', 'dylib', 'html', 'htm', 'xhtml', 'svg', 'svgz', 'xml', 'xsl', 'swf', 'docm', 'xlsm',
        'pptm', 'dotm', 'xlam', 'chm', 'inf', 'ocx', 'drv', 'ws', 'sct', 'shs', 'appx', 'appxbundle', 'msix', 'scf', 'application', 'manifest', 'jnlp'];

    /**
     * @param mixed $raw the request's `attachments` value (null/absent = none)
     * @return list<array{filename:string,content_type:string,bytes:string}>
     * @throws ApiError 422 attachment_rejected / 413 too_large
     */
    public static function validate(mixed $raw, PanelLimits $limits): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw self::bad('attachments must be a list.');
        }
        if (count($raw) > $limits->attachmentsMax) {
            throw self::bad('At most ' . $limits->attachmentsMax . ' attachments are accepted.');
        }
        $out = [];
        $total = 0;
        $names = [];
        foreach ($raw as $i => $a) {
            $n = $i + 1;
            if (!is_array($a) || !is_string($a['filename'] ?? null) || !is_string($a['content_type'] ?? null) || !is_string($a['data_base64'] ?? null)) {
                throw self::bad("Attachment $n needs filename, content_type and data_base64 strings.");
            }
            $b64 = $a['data_base64'];
            // size gate BEFORE decoding so an oversized value never allocates
            if (strlen($b64) > (int) (ceil($limits->attachmentBytesMax / 3) * 4) + 4) {
                throw new ApiError(413, 'too_large', "Attachment $n is larger than " . $limits->attachmentBytesMax . ' bytes.');
            }
            $bytes = base64_decode($b64, true);
            if ($bytes === false || $bytes === '') {
                throw self::bad("Attachment $n is not valid base64 data.");
            }
            if (strlen($bytes) > $limits->attachmentBytesMax) {
                throw new ApiError(413, 'too_large', "Attachment $n is larger than " . $limits->attachmentBytesMax . ' bytes.');
            }
            $total += strlen($bytes);
            if ($total > $limits->attachmentsTotalMax) {
                throw new ApiError(413, 'too_large', 'The attachments together are larger than ' . $limits->attachmentsTotalMax . ' bytes.');
            }
            $name = self::filename($a['filename'], $n);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext === '' || !isset(self::TYPES[$ext]) || !in_array($ext, $limits->allowedExtensions, true)) {
                throw self::bad("Attachment $n: files of this type are not accepted.");
            }
            $parts = explode('.', strtolower($name));
            array_shift($parts);   // the first part is the name, not an extension ("app.log" is fine)
            foreach ($parts as $part) {
                if (in_array($part, self::DANGEROUS, true)) {
                    throw self::bad("Attachment $n: executable, script and markup files are not accepted.");
                }
            }
            [$canonical, $accepted, $kind] = self::TYPES[$ext];
            $declared = strtolower(trim(explode(';', $a['content_type'])[0]));
            if (!in_array($declared, $accepted, true)) {
                throw self::bad("Attachment $n: the content type does not match the file extension.");
            }
            if (self::looksExecutable($bytes)) {
                throw self::bad("Attachment $n: executable content is not accepted.");
            }
            if (!self::magicOk($bytes, $kind)) {
                throw self::bad("Attachment $n: the file content does not match its type.");
            }
            $key = strtolower($name);
            if (isset($names[$key])) {
                $name = substr($name, 0, -(strlen($ext) + 1)) . '-' . $n . '.' . $ext;
            }
            $names[strtolower($name)] = true;
            $out[] = ['filename' => $name, 'content_type' => $canonical, 'bytes' => $bytes];
        }

        return $out;
    }

    /** A basename of letters, digits, dot, dash, underscore, space and brackets; never empty, never starts with a dot, at most 100 characters. */
    public static function filename(string $given, int $n = 1): string
    {
        $s = PanelText::input($given, 'filename', 500, false, true);
        $s = str_replace('\\', '/', $s);
        $s = basename($s);
        $s = (string) preg_replace('/[^A-Za-z0-9._\-() \[\]]+/u', '_', $s);
        $s = trim($s, ". \t");
        $s = (string) preg_replace('/_{2,}/', '_', $s);
        if (mb_strlen($s) > 100) {
            $ext = pathinfo($s, PATHINFO_EXTENSION);
            $s = mb_substr(pathinfo($s, PATHINFO_FILENAME), 0, 90) . ($ext !== '' ? '.' . substr($ext, 0, 8) : '');
        }
        if ($s === '' || !str_contains($s, '.')) {
            throw self::bad("Attachment $n needs a filename with an extension.");
        }
        if ($s[0] === '-' || preg_match('/^(?:con|prn|aux|nul|com[0-9]|lpt[0-9])[ .]*(?:\.|$)/i', $s) === 1) {
            throw self::bad("Attachment $n: this file name is not accepted.");
        }

        return $s;
    }

    private static function bad(string $msg): ApiError
    {
        return new ApiError(422, 'attachment_rejected', $msg);
    }

    private static function looksExecutable(string $b): bool
    {
        $h4 = substr($b, 0, 4);

        return str_starts_with($b, 'MZ') || $h4 === "\x7fELF" || str_starts_with($b, '#!')
            || in_array($h4, ["\xCF\xFA\xED\xFE", "\xCE\xFA\xED\xFE", "\xFE\xED\xFA\xCE", "\xFE\xED\xFA\xCF", "\xCA\xFE\xBA\xBE"], true)
            || str_starts_with($b, "\xD0\xCF\x11\xE0")   // OLE container (legacy Office, MSI)
            || str_starts_with($b, "PK\x03\x04");        // ZIP (jar, apk, docx, ...): no archive is accepted
    }

    private static function magicOk(string $b, string $kind): bool
    {
        return match ($kind) {
            'png' => str_starts_with($b, "\x89PNG\r\n\x1a\n"),
            'jpeg' => str_starts_with($b, "\xFF\xD8\xFF"),
            'gif' => str_starts_with($b, 'GIF87a') || str_starts_with($b, 'GIF89a'),
            'webp' => strlen($b) >= 12 && str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP',
            'pdf' => str_starts_with($b, '%PDF-'),
            'text' => self::plainText($b),
            'csv' => self::plainText($b, true) && self::csvOk($b),
            default => false,
        };
    }

    /**
     * CRR-17 / R5-1: does any start tag carry an event handler, `href`, `src`, `srcdoc`, `style`, `action` or `formaction`? The attributes are read the way an HTML tokenizer reads them
     * (one pass with a single cursor, so the time is linear in the size of the file: nothing is scanned twice, which an adversarial file of a million `<x a=b` cannot change):
     * a tag name runs to white space, `/` or `>`; a tag name that ends at `>` (or the end) has no attributes and the cursor moves past it; attribute names end at white space, `/`,
     * `=` or `>` (a `<`, a quote or anything else inside a name is an ordinary character); a value after `=` is quoted only when it starts with a quote (an unterminated quote, or a tag start inside the quoted value, refuses the file: nothing hides a tag from
     * the scan), otherwise it ends at white space or `>`; a tag ends at `>`. A `<` followed by white space and then a letter counts as a tag start (a browser reads `< x` as text; refusing it is the
     * safe direction). A step budget bounds the work as a second line: when it runs out the file is refused.
     */
    private static function hasActiveAttribute(string $b, ?int $budget = null): bool
    {
        // no attribute name that matters occurs anywhere: nothing to look for (one pass of the regex engine, which keeps ordinary files and most adversarial ones out of the tokenizer)
        if (preg_match('/(?:on[a-z]+|href|src|srcdoc|style|action|formaction|xlink:href)\s*=/i', $b) === 0) {
            return false;
        }
        $n = strlen($b);
        // The budget counts tag starts and attributes: 100 000 plus one step per 16 bytes. A text file that holds more markup than that is not a log or a note and is refused. (A parameter
        // only so a test can reach the branch that runs out of it.)
        $budget ??= 100000 + intdiv($n, 16);
        $i = 0;
        $steps = 0;
        // the next tag start is found by the regex engine (a run of `<` or of white space is skipped natively, not one PHP step at a time)
        while (preg_match('/<\s*[A-Za-z]/', $b, $m, PREG_OFFSET_CAPTURE, $i) === 1) {
            if (++$steps > $budget) {
                return true;
            }
            $j = $m[0][1] + strlen($m[0][0]) - 1;   // the first letter of the tag name
            $j += strcspn($b, " \t\r\n\f/>", $j);   // the tag name
            if ($j >= $n || $b[$j] === '>') {
                $i = $j;   // no attributes; a `<` inside the name belongs to the name
                continue;
            }
            $i = $j;
            while ($i < $n) {
                if (++$steps > $budget) {
                    return true;
                }
                $i += strspn($b, " \t\r\n\f/", $i);
                if ($i >= $n || $b[$i] === '>') {
                    break;
                }
                $len = strcspn($b, " \t\r\n\f/=>", $i);
                if ($len === 0) {
                    ++$i;   // a `=` where a name should start
                    continue;
                }
                $name = substr($b, $i, min($len, 64));   // an attribute name longer than 64 bytes is none of the names that matter; only its tail is looked at
                $name = $len > 64 ? substr($b, $i + $len - 64, 64) : $name;
                $i += $len;
                $i += strspn($b, " \t\r\n\f", $i);
                if ($i >= $n || $b[$i] !== '=') {
                    continue;
                }
                if (preg_match('/(?:^|[^A-Za-z0-9_])(?:on[a-z]+|href|src|srcdoc|style|action|formaction|xlink:href)$/iD', $name) === 1) {
                    return true;
                }
                ++$i;
                $i += strspn($b, " \t\r\n\f", $i);
                if ($i < $n && ($b[$i] === '"' || $b[$i] === "'")) {
                    $close = strpos($b, $b[$i], $i + 1);
                    if ($close === false) {
                        return true;   // the quote is never closed: what follows may be a tag or a handler to a parser that began somewhere else; refuse
                    }
                    // fail closed: a tag start inside the skipped value would never be looked at (a parser that began its quote in another context reads it as a tag)
                    if (preg_match('/<\s*[A-Za-z]/', substr($b, $i + 1, $close - $i - 1)) === 1) {
                        return true;
                    }
                    $i = $close + 1;
                } else {
                    $i += strcspn($b, " \t\r\n\f>", $i);
                }
            }
            // the tag ended at `>` (or the file did); the scan for the next `<` goes on from here
        }

        return false;
    }

    private static function plainText(string $b, bool $csv = false): bool
    {
        if (!mb_check_encoding($b, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $b) === 1) {
            return false;   // binary, NUL, or terminal escape sequences
        }
        // markup anywhere in the file (it used to be the first 8 KiB only)
        // CORE-DS-32 / CR6: markup that is ACTIVE as HTML, not every `<letter`: a declaration or processing instruction (`<!`, `<?`), an opening or closing tag of an element that can run
        // or load something (a list, because a log full of <DIR>, <init>, <module> or "Name <a@b>" is ordinary text), or any tag with an event handler or a URL/style attribute
        // (`<x onerror=...>`). The real control stays what it was: the file is served as an attachment with nosniff.
        // CRR-17: the tag name is anything up to a space, `/` or `>` (`<x.y onclick=...>`, `<x"y onclick=...>`: a quote or `=` in a name is an ordinary character), the attributes are read the way an HTML tokenizer reads them (a quote is special only right after `=`;
        // anywhere else it is an ordinary character: `<x data=it's onclick=...>`, `<x a"b onclick=...>`), and a regex that errors (a backtracking limit) counts as a match: fail closed.
        if (preg_match('/<[!?]/', $b) !== 0
            || preg_match('/<\/?\s*(?:script|img|image|body|svg|iframe|frame|frameset|a|form|style|meta|base|link|object|embed|applet|html|head|title|input|button|select|textarea|details|summary|video|audio|source|track|math|marquee|template|noscript|canvas|portal|xml|xsl\w*|xmp|noembed|noframes|plaintext|listing)(?=[\s\/>])/i', $b) !== 0
            || self::hasActiveAttribute($b)) {
            return false;
        }
        // A CSV gets the cell rule on EVERY cell, the first one included (csvOk). Looking only at the first character of the file is what let a formula in a later cell through
        // (CORE-API-1 / CORE-DATA-1), and it refused a file that starts with a plain number such as -5 while accepting the same number in any later cell.
        if ($csv) {
            return true;
        }
        // txt and log: a spreadsheet opens the file as a formula when the first character says so (tab and CR are control characters, but the check above lets a tab and a CR through).
        // Only the first character: a txt or log file is not split into cells, and refusing every line that starts with - or + would refuse ordinary notes, lists and diffs.
        $first = str_starts_with($b, "\xEF\xBB\xBF") ? substr($b, 3) : $b;
        $start = substr($first, 0, 1);
        if ($start !== '' && strpbrk($start, "=+-@\t\r") !== false) {
            return false;
        }

        return true;
    }

    /** A cell whose first significant character is one of these is a formula to a spreadsheet (OWASP CSV injection; LF only ever leads a quoted cell). */
    private const CSV_FORMULA_LEAD = "=+-@\t\r\n";

    /** The non-ASCII white space skipped before that character, as UTF-8 bytes: the Unicode space separators (no-break, en/em, figure, narrow, ideographic, ...), zero-width spaces and joiners, word joiner, BOM. */
    private const CSV_SPACE_NONASCII = '\xC2\xA0|\xE1\x9A\x80|\xE1\xA0\x8E|\xE2\x80[\x80-\x8D\xAF]|\xE2\x81[\x9F\xA0]|\xE3\x80\x80|\xEF\xBB\xBF';

    /** What a spreadsheet skips before the character it looks at: white space, one optional quote, white space. */
    private const CSV_LEAD_SKIP = '/^(?:[ ]|' . self::CSV_SPACE_NONASCII . ')*+"?(?:[ ]|' . self::CSV_SPACE_NONASCII . ')*+/';

    /** A whole cell that is a plain number (`-12.5`, `+3`, `1e5`, `+1,5`, `-1,234.56`, `-5%`, `+44 20 7946 0958`) or signs alone (`-`, `--`), with white space and a quote around it. */
    private const CSV_INERT_CELL = '/^(?:[ \t\r\n]|' . self::CSV_SPACE_NONASCII . ')*+"?(?:[ \t\r\n]|' . self::CSV_SPACE_NONASCII . ')*+'
        . '(?:[+-]?(?=[.,]?\d)(?:[\d.,\' ]|\xC2\xA0|\xE2\x80\xAF)*+(?:[eE][+-]?\d++)?%?|[=+\-@ ]*+)'
        . '(?:[ \t\r\n]|' . self::CSV_SPACE_NONASCII . ')*+"?(?:[ \t\r\n]|' . self::CSV_SPACE_NONASCII . ')*+$/D';

    /** How many cells one regex call may consume, and how many quote pairs a quoted cell may hold to be consumed there (PCRE copies a bounded group once per repeat, so these stay small). */
    private const CSV_CELLS_PER_CALL = 32;
    private const CSV_QUOTE_PAIRS = 8;

    /** A first line `sep=X` may name one of these (Excel honours the line); any other character is refused, so no separator can be a character the cell patterns read as content. */
    private const CSV_SEP_ALLOWED = ",;\t!#$&()*/:<>?[\\]^_`{|}~";

    /**
     * CSV only (CORE-DS-33, reopened by CORE-API-1 / CORE-DATA-1 and closed here): no cell may be a formula. The file is read the way a spreadsheet reads it, with RFC 4180 quoting
     * (a quote opens a quoted cell only as the cell's first byte, `""` is a quote inside it, a separator or line break inside it belongs to the cell, and what follows the closing
     * quote up to the next separator is part of the cell too). It is read once per separator a spreadsheet may use, because the reader, not the file, picks the separator: comma
     * (Excel in most English locales), semicolon (Excel in most of Europe), tab, all three at once (LibreOffice with several boxes ticked), and the one a first line `sep=X` names
     * (Excel honours it). A quote that one reader treats as the start of a quoted cell is plain text to another, so every reading must pass.
     *
     * In every reading, a cell is refused when its first character after white space, one optional quote and white space again is `=`, `+`, `-`, `@`, a tab or a line break,
     * unless the whole cell is a plain number (`-12.5`, `+3`, `1e5`, `+1,5`, `-1,234.56`, `-5%`, digits and spaces such as `+44 20 7946 0958`) or signs alone (`-`, `--`): none of
     * these can call a function, follow a reference or start DDE. Nothing else is looked for: no function names, no `|`, no length window (the 80-character window and the
     * function-name pattern were the bypass).
     *
     * What this refuses that is not an attack, on purpose (refusing the file keeps the stored bytes exactly as sent, which apostrophe-neutralising would not):
     * a cell such as `- item`, `-- note`, `+1 (555) 123-4567` or `@handle`; a comma-separated row whose FIRST cell starts with a sign followed by more cells (`-5,apples`: a
     * semicolon reader sees the whole row as one cell starting with `-`); a line inside a multi-line quoted cell that starts with `= + - @` when the cell is not in the first
     * column (a reader with another separator sees that quote as text and the line as a new row); a cell that starts with a tab followed by text in a comma or semicolon
     * reading; a quoted cell that is never closed (readers disagree on where it ends); a `sep=` line naming a letter, digit, space, quote or `= + - @ . , ' %`.
     *
     * Linear time: in each reading one regex call consumes up to 32 whole cells that are plainly fine (one that starts with an ordinary character, after spaces and an optional
     * quote when unquoted; a quoted one whose content starts so, with at most 8 quote pairs; a plain number or signs alone, quoted or not); only the rest reach PHP, one cell
     * at a time, and a quoted one is closed with one regex search. Every pattern is a run of a character class or a small bounded repetition, so the regex stack does not grow with the
     * file. A step budget bounds the work as a second line: a file in which more than 100 000 + 1 per 32 bytes of cells need that closer look (no ordinary CSV comes near it) is refused.
     */
    private static function csvOk(string $b): bool
    {
        $b = str_starts_with($b, "\xEF\xBB\xBF") ? substr($b, 3) : $b;
        // NEL, LINE SEPARATOR and PARAGRAPH SEPARATOR: a reader that starts a row at them sees a new cell, so they count as line breaks (only more cells get looked at)
        $b = str_replace(["\xC2\x85", "\xE2\x80\xA8", "\xE2\x80\xA9"], "\n", $b);
        $readings = [',', ';', "\t", ",;\t"];
        if (preg_match('/^"?sep=([^\r\n])/i', $b, $m) === 1) {
            if (!str_contains(self::CSV_SEP_ALLOWED, $m[1])) {
                return false;
            }
            if (!in_array($m[1], $readings, true)) {
                $readings[] = $m[1];
            }
        }
        $budget = 100000 + intdiv(strlen($b), 32);
        foreach ($readings as $separators) {
            if (!self::csvReadingOk($b, $separators, $budget)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One reading of the file with $separators as the cell separators (any one of them ends a cell); false at the first cell that is a formula, at a quote that is never
     * closed, when the budget of cells that need a closer look runs out, or on a regex error (fail closed). (The budget is a parameter so a test can reach that branch.)
     */
    private static function csvReadingOk(string $b, string $separators, int &$budget): bool
    {
        $stop = $separators . "\r\n";
        $s = preg_quote($stop, '/');
        $blank = str_contains($separators, "\t") ? '[ ]' : '[ \t]';
        // the first character of a fine cell: not a quote, white space, control, formula character or separator, and not a Unicode space
        $ord = '(?:[^"\x00-\x20=+\-@\x7F-\xFF' . $s . ']|(?!' . self::CSV_SPACE_NONASCII . ')[\xC2-\xF4])';
        $num = static fn (string $chars): string => '[+-]?+(?=[.,]?\d)[0-9' . preg_quote($chars, '/') . ']*+(?:[eE][+-]?\d++)?+%?+';
        $fine = '(?:'
            // unquoted, ordinary first character (after spaces and a literal quote: Excel reads a quote that is not the cell's first byte as text)
            . '(?:[ ]++"?+[ ]*+)?+' . $ord . '[^' . $s . ']*+'
            // unquoted plain number or signs alone (or nothing: an empty cell)
            . '|(?!")' . $blank . '*+"?+' . $blank . '*+(?:' . $num(str_replace(str_split($separators), '', ".,' ")) . '|[=+\-@ ]*+)' . $blank . '*+"?+' . $blank . '*+'
            // quoted, the content starts with an ordinary character; separators, line breaks and up to 8 quote pairs inside; text after the closing quote
            . '|"[ ]*+' . $ord . '[^"]*+(?:""[^"]*+){0,' . self::CSV_QUOTE_PAIRS . '}+"(?!")[^' . $s . ']*+'
            // quoted plain number or signs alone (or nothing), nothing after the closing quote
            . '|"[ ]*+(?:' . $num(".,' ") . '|[=+\-@ ]*+)[ ]*+"(?!")'
            . ')';
        $run = '/\G(?:' . $fine . '[' . $s . ']){0,' . self::CSV_CELLS_PER_CALL . '}+/';
        $n = strlen($b);
        $i = 0;   // the start of a cell
        while (true) {
            $ran = preg_match($run, $b, $m, 0, $i);
            if ($ran !== 1) {
                return false;   // a regex error: fail closed
            }
            if ($m[0] !== '') {
                $i += strlen($m[0]);
                if ($i >= $n) {
                    return true;   // the file ends with a separator or line break: the last cell is empty
                }
                continue;
            }
            // the cell at $i needs a closer look (or it is the last one, which no separator follows)
            if (--$budget < 0) {
                return false;
            }
            if ($i < $n && $b[$i] === '"') {
                // a quoted cell ends at the last quote of the first run of an odd number of quotes (a pair is one literal quote)
                $found = preg_match('/(?:\G|(?<!"))(?:"")*+"(?!")/', $b, $q, PREG_OFFSET_CAPTURE, $i + 1);
                if ($found !== 1) {
                    return false;   // never closed (readers disagree on where such a cell ends), or a regex error
                }
                $close = $q[0][1] + strlen($q[0][0]) - 1;
                $cellEnd = $close + 1 + strcspn($b, $stop, $close + 1);
                $value = str_replace('""', '"', substr($b, $i + 1, $close - $i - 1)) . substr($b, $close + 1, $cellEnd - $close - 1);
            } else {
                $cellEnd = $i + strcspn($b, $stop, $i);
                $value = substr($b, $i, $cellEnd - $i);
            }
            if (!self::csvCellOk($value)) {
                return false;
            }
            if ($cellEnd >= $n) {
                return true;
            }
            $i = $cellEnd + 1;
        }
    }

    /** One cell's value as a spreadsheet sees it (the quotes of a quoted cell removed): false when it would be read as a formula. */
    private static function csvCellOk(string $v): bool
    {
        if (preg_match(self::CSV_LEAD_SKIP, $v, $m) !== 1) {
            return false;
        }
        $at = strlen($m[0]);
        if ($at >= strlen($v) || strpbrk($v[$at], self::CSV_FORMULA_LEAD) === false) {
            return true;
        }

        // the whole cell may still be a plain number or signs alone
        return preg_match(self::CSV_INERT_CELL, $v) === 1;
    }
}
