<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

/**
 * The one place that builds the response of a binary download (the hosted agent update, the stamped installer, an administrator's
 * installer download): the exact header set the golden transcripts pin, a file body that the emitter verifies and streams, and a
 * file name that can never inject a header.
 *
 * @api
 */
final class FileDownload
{
    public const FALLBACK_NAME = 'RivetIT-Agent.exe';

    public static function response(RmmFileBody $file, string $filename): RmmResponse
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,120}$/', $filename) !== 1) {
            $filename = self::FALLBACK_NAME;   // header injection is impossible: only this alphabet reaches a header
        }

        return new RmmResponse(200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ], null, $file);
    }
}
