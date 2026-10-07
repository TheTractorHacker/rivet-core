<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Crypto;

use RivetCore\Rmm\RmmProtocol;

/**
 * Strips credentials from job output before it is stored or shown. Best effort: patterns, not a guarantee. The pattern set and its
 * order are those of RivetIT src/EndpointAgent/Redactor.php, ported unchanged.
 *
 * @api
 */
final class Redactor
{
    public const MASK = RmmProtocol::REDACTION_MASK;

    public static function redact(string $s): string
    {
        $m = self::MASK;
        $rules = [
            // PEM private key blocks.
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s' => $m,
            // Authorization headers and bearer strings.
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i' => 'Bearer ' . $m,
            '/\b(Authorization|Proxy-Authorization)\s*[:=]\s*\S+(\s+\S+)?/i' => '$1: ' . $m,
            // JSON Web Tokens.
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*/' => $m,
            // key=value / key: value with secret-looking names.
            '/\b([A-Za-z0-9_.-]*(?:password|passwd|pwd|passphrase|secret|token|api[_-]?key|apikey|access[_-]?key|private[_-]?key|client[_-]?secret|credential)[A-Za-z0-9_.-]*)(["\']?\s*[:=]\s*["\']?)[^\s"\',;]+/i' => '$1$2' . $m,
            // PowerShell -Password / -Token style switches and ConvertTo-SecureString -String.
            '/(-(?:password|token|secret|apikey|key)\s+)(["\']?)[^\s"\']+\2/i' => '$1' . $m,
            '/(ConvertTo-SecureString\s+(?:-String\s+)?)(["\'])[^"\']*\2/i' => '$1' . $m,
            // Provider key shapes.
            '/\bAKIA[0-9A-Z]{16}\b/' => $m,
            '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/' => $m,
            '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/' => $m,
            // This application's own credential formats.
            '/\brvte1\.[0-9a-f]{12}\.[0-9a-f]{40}\b/' => $m,
            '/\b[0-9a-f]{64}\b/' => $m,
        ];
        foreach ($rules as $re => $to) {
            $s = preg_replace($re, $to, $s) ?? $s;
        }
        return $s;
    }
}
