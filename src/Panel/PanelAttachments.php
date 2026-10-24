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
 *  - a text-like file (txt, log, csv) must not start with a spreadsheet formula character (`=`, `+`, `-`, `@`, tab or carriage return, after an optional byte-order mark), a CSV
 *    must not have a cell that starts with `=` or looks like a DDE or function call, and the whole text, not only its start, is searched for markup (security review RC36 L5).
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
        if ($s[0] === '-' || preg_match('/^(?:con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i', $s) === 1) {
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
            'csv' => self::plainText($b) && self::csvOk($b),
            default => false,
        };
    }

    private static function plainText(string $b): bool
    {
        if (!mb_check_encoding($b, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $b) === 1) {
            return false;   // binary, NUL, or terminal escape sequences
        }
        // markup anywhere in the file (it used to be the first 8 KiB only)
        if (preg_match('/<\s*(script|html|iframe|svg|object|embed|\?php|!doctype)/i', $b) === 1) {
            return false;
        }
        // a spreadsheet opens the file as a formula when the first character says so (tab and CR are control characters, but the check above lets a tab and a CR through)
        $start = str_starts_with($b, "\xEF\xBB\xBF") ? substr($b, 3, 1) : substr($b, 0, 1);
        if ($start !== '' && strpbrk($start, "=+-@\t\r") !== false) {
            return false;
        }

        return true;
    }

    /** CSV only: a cell that starts with `=`, a function or DDE call (`+cmd|' /C calc'!A0`, `@SUM(...)`), at the start of the file, a line or after a delimiter. */
    private static function csvOk(string $b): bool
    {
        $cell = '(?:^|[\r\n,;\t])[ ]*';

        return preg_match('/' . $cell . '=/', $b) !== 1
            && preg_match('/' . $cell . '[+\-@][ ]*[A-Za-z_][A-Za-z0-9_.]*[ ]*\(/', $b) !== 1
            && preg_match('/' . $cell . '[+\-@=][^,;\r\n]{0,80}\|/', $b) !== 1;
    }
}
