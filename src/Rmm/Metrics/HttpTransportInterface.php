<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Metrics;

/**
 * The smallest HTTP client an external metric store adapter needs. Implementations must not follow redirects, must verify TLS, must
 * bound the response size and the time, and must throw {@see ExternalStoreException} (never anything else) when no HTTP response was
 * received. Any HTTP status, including 4xx and 5xx, is a normal return: the adapter decides what it means.
 *
 * @api
 */
interface HttpTransportInterface
{
    /**
     * @param list<string> $headers ready "Name: value" lines
     * @return array{status:int, body:string}
     * @throws ExternalStoreException on a transport failure (DNS, connect, TLS, timeout, oversized response, URL refused)
     */
    public function request(string $method, string $url, array $headers, string $body, int $timeoutSeconds): array;
}
