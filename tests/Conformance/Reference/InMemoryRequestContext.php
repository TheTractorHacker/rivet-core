<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Contracts\RequestContextInterface;

/**
 * Reference RequestContextInterface: builds the context from a header-like array the way a web edition would from
 * $_SERVER, validating every value and generating the request id itself instead of trusting a client header.
 */
final class InMemoryRequestContext implements RequestContextInterface
{
    private string $requestId;

    /** @param array<string,mixed> $server */
    public function __construct(private array $server = [])
    {
        $this->requestId = bin2hex(random_bytes(8));
    }

    public function ipAddress(): ?string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? null;

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    public function userAgent(): ?string
    {
        $ua = $this->server['HTTP_USER_AGENT'] ?? null;
        if (!is_string($ua)) {
            return null;
        }
        $ua = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $ua) ?? '';

        return substr($ua, 0, 255);
    }

    public function requestId(): ?string
    {
        return $this->requestId;   // never taken from a request header
    }
}
