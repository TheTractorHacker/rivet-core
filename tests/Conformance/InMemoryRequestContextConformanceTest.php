<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\RequestContextInterface;
use RivetCore\Tests\Conformance\Reference\InMemoryRequestContext;

/** Reference run, fed hostile "headers": a forged request id, CRLF in the user agent, a non-address as the peer. */
final class InMemoryRequestContextConformanceTest extends RequestContextConformanceTestCase
{
    protected function context(): RequestContextInterface
    {
        return new InMemoryRequestContext([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => "Mozilla\r\nX-Injected: yes\x00",
            'HTTP_X_REQUEST_ID' => "forged\nid",
        ]);
    }
}
