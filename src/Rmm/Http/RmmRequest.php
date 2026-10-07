<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

/**
 * A framework-neutral request. The edition builds it (TLS/proxy trust, client IP and bearer extraction stay in the edition);
 * Core performs the bounded body read itself.
 *
 * @api
 */
final class RmmRequest
{
    /**
     * @param string $method 'GET' | 'POST' ...
     * @param string $endpoint 'agent_enroll' | 'agent_checkin' | 'agent_jobs' | 'agent_update' | 'agent_installer' | 'endpoint_devices'
     * @param list<string> $pathSegments technician API: segments after the resource
     * @param array<string,string> $query
     * @param array<string,string> $headers lower-case names; Core reads authorization and content-type
     * @param bool $secureTransport decided by the edition (trusted-proxy rule)
     * @param resource|null $bodyStream
     */
    public function __construct(
        public readonly string $method,
        public readonly string $endpoint,
        public readonly array $pathSegments,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $clientIp,
        public readonly ?string $userAgent,
        public readonly bool $secureTransport,
        public readonly ?int $declaredLength,
        public readonly mixed $bodyStream,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
