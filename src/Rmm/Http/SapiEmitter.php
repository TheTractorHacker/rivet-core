<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

/**
 * Sends an {@see RmmResponse} through PHP's SAPI. It never exits. A file body is verified (size, then SHA-256 when given)
 * before the first byte is sent and streamed in 64 KiB chunks; a file that fails verification becomes a generic 500.
 *
 * @api
 */
final class SapiEmitter
{
    public const CHUNK = 65536;

    /** @var \Closure(int):void */
    private \Closure $status;
    /** @var \Closure(string):void */
    private \Closure $header;
    /** @var \Closure(string):void */
    private \Closure $out;

    /**
     * Seams for tests; the defaults are http_response_code(), header() and echo.
     *
     * @param (\Closure(int):void)|null $status
     * @param (\Closure(string):void)|null $header
     * @param (\Closure(string):void)|null $out
     */
    public function __construct(?\Closure $status = null, ?\Closure $header = null, ?\Closure $out = null)
    {
        $this->status = $status ?? static function (int $code): void {
            http_response_code($code);
        };
        $this->header = $header ?? static function (string $line): void {
            header($line);
        };
        $this->out = $out ?? static function (string $bytes): void {
            echo $bytes;
        };
    }

    public function emit(RmmResponse $response): void
    {
        $file = $response->file;
        if ($file !== null && !self::verified($file)) {
            error_log('endpoint agent: file body failed verification: ' . basename($file->path));
            $response = RmmResponse::error(new ApiError(500, 'internal', 'Internal error.'));
            $file = null;
        }
        ($this->status)($response->status);
        foreach ($response->headers as $name => $value) {
            ($this->header)($name . ': ' . $value);
        }
        if ($file !== null) {
            ($this->header)('Content-Length: ' . $file->totalLength());
            $this->stream($file);

            return;
        }
        if ($response->body !== null && $response->body !== '') {
            ($this->out)($response->body);
        }
    }

    private static function verified(RmmFileBody $file): bool
    {
        if (!is_file($file->path) || filesize($file->path) !== $file->length) {
            return false;
        }

        return $file->sha256 === null || hash_equals(strtolower($file->sha256), (string) hash_file('sha256', $file->path));
    }

    private function stream(RmmFileBody $file): void
    {
        $h = fopen($file->path, 'rb');
        if ($h === false) {
            return;
        }
        $left = $file->length;
        while ($left > 0 && !feof($h)) {
            $chunk = fread($h, min(self::CHUNK, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $left -= strlen($chunk);
            ($this->out)($chunk);
        }
        fclose($h);
        if ($file->trailer !== null && $file->trailer !== '') {
            ($this->out)($file->trailer);
        }
    }
}
