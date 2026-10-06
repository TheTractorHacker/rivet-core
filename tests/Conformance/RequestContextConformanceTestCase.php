<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\RequestContextInterface;

/**
 * Behaviour every RequestContextInterface adapter must have. The values end up in audit rows, so they must be safe to
 * store and to print: no control characters (log forging), a real IP address, and a request id the SERVER chose
 * (RivetCore once stored a client-chosen id; see CHANGELOG 0.7.x).
 *
 * Extend it and implement context(). For an adapter that serves a web request, return one built from a simulated request
 * that carries hostile header values: that is the strongest check.
 */
abstract class RequestContextConformanceTestCase extends TestCase
{
    abstract protected function context(): RequestContextInterface;

    public function testEveryValueIsNullOrString(): void
    {
        $c = $this->context();
        foreach (['ipAddress', 'userAgent', 'requestId'] as $m) {
            $v = $c->$m();
            $this->assertTrue($v === null || is_string($v), "$m() must return ?string, got " . get_debug_type($v));
        }
    }

    public function testIpAddressIsAValidAddressWhenPresent(): void
    {
        $ip = $this->context()->ipAddress();
        if ($ip !== null) {
            $this->assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP), 'ipAddress() returned a non-address: ' . $ip);
        }
        $this->addToAssertionCount(1);
    }

    public function testNoValueContainsControlCharacters(): void
    {
        $c = $this->context();
        foreach (['ipAddress', 'userAgent', 'requestId'] as $m) {
            $v = $c->$m();
            if ($v !== null) {
                $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $v), "$m() contains a control character: " . bin2hex($v));
            }
        }
        $this->addToAssertionCount(1);   // an adapter that returns only nulls has nothing to check
    }

    public function testRequestIdIsStableWithinOneContext(): void
    {
        $c = $this->context();
        $this->assertSame($c->requestId(), $c->requestId(), 'one request must have one id');
    }

    public function testRequestIdFitsTheAuditColumn(): void
    {
        $id = $this->context()->requestId();
        if ($id !== null) {
            $this->assertLessThanOrEqual(64, strlen($id), 'audit_events.request_id is varchar(64)');
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:-]+$/', $id, 'a request id should be a plain token');
        }
        $this->addToAssertionCount(1);
    }
}
