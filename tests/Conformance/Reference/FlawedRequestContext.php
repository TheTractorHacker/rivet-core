<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Contracts\RequestContextInterface;

/**
 * Behaves like an edition's ServerRequestContext (reads $_SERVER, server-assigned id) or has a named flaw:
 * trust_client_id, unstable_id, ip_placeholder, throws_cli, long_id, ctrl_ua, bad_id_chars.
 */
final class FlawedRequestContext implements RequestContextInterface
{
    private static ?string $generated = null;

    public function __construct(private ?string $flaw = null)
    {
    }

    public function ipAddress(): ?string
    {
        if (!isset($_SERVER['REMOTE_ADDR'])) {
            if ($this->flaw === 'throws_cli') {
                throw new \RuntimeException('Undefined REMOTE_ADDR');
            }

            return $this->flaw === 'ip_placeholder' ? 'unknown' : null;
        }

        return $_SERVER['REMOTE_ADDR'];
    }

    public function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return $this->flaw === 'ctrl_ua' && $ua !== null ? $ua . "\r\nX-Injected: 1" : $ua;
    }

    public function requestId(): ?string
    {
        return match ($this->flaw) {
            'trust_client_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? ($_SERVER['RIVET_REQUEST_ID'] ?? 'req_x'),
            'unstable_id' => 'req_' . bin2hex(random_bytes(8)),
            'long_id' => str_repeat('r', 200),
            'bad_id_chars' => 'req id with spaces/slashes',
            default => $_SERVER['RIVET_REQUEST_ID'] ?? (self::$generated ??= 'req_' . bin2hex(random_bytes(8))),
        };
    }
}
