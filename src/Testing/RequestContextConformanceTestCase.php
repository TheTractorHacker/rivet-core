<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\RequestContextInterface;

/**
 * Conformance kit for {@see RequestContextInterface}.
 *
 * Checks: every field is null or a sane string (IP literal of at most 45 characters, request id of 1-64 characters from
 * [A-Za-z0-9._:-], user agent without control characters); no exception when there is no request at all (CLI, cron);
 * the request id is stable for the whole request and is never a value the client chose (X-Request-ID header).
 *
 * The case simulates a request by writing the usual server variables ({@see self::applyRequest()}); override that and
 * {@see self::clearRequest()} when the edition reads its facts from somewhere else.
 *
 * @api
 */
abstract class RequestContextConformanceTestCase extends TestCase
{
    use UntypedValues;

    /** @var array<string,mixed> */
    private array $serverBackup = [];
    /** @var array<string,mixed> */
    private array $globalsBackup = [];

    private const SERVER_KEYS = ['REMOTE_ADDR', 'HTTP_USER_AGENT', 'HTTP_X_REQUEST_ID', 'HTTP_X_FORWARDED_FOR', 'RIVET_REQUEST_ID'];
    private const GLOBAL_KEYS = ['session_ip', 'session_user_agent'];

    /** A NEW context each call: the case builds one per simulated request. */
    abstract protected function context(): RequestContextInterface;

    /**
     * Make the world look like a web request. Keys: ip, user_agent, client_request_id (what a client sent in X-Request-ID).
     *
     * @param array{ip?:string,user_agent?:string,client_request_id?:string} $facts
     */
    protected function applyRequest(array $facts): void
    {
        if (isset($facts['ip'])) {
            $_SERVER['REMOTE_ADDR'] = $facts['ip'];
        }
        if (isset($facts['user_agent'])) {
            $_SERVER['HTTP_USER_AGENT'] = $facts['user_agent'];
        }
        if (isset($facts['client_request_id'])) {
            $_SERVER['HTTP_X_REQUEST_ID'] = $facts['client_request_id'];
        }
    }

    /** Make the world look like CLI/cron: no request facts at all. */
    protected function clearRequest(): void
    {
        foreach (self::SERVER_KEYS as $k) {
            unset($_SERVER[$k]);
        }
        foreach (self::GLOBAL_KEYS as $k) {
            unset($GLOBALS[$k]);
        }
    }

    protected function setUp(): void
    {
        foreach (self::SERVER_KEYS as $k) {
            $this->serverBackup[$k] = $_SERVER[$k] ?? null;
        }
        foreach (self::GLOBAL_KEYS as $k) {
            $this->globalsBackup[$k] = $GLOBALS[$k] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->serverBackup as $k => $v) {
            if ($v === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $v;
            }
        }
        foreach ($this->globalsBackup as $k => $v) {
            if ($v === null) {
                unset($GLOBALS[$k]);
            } else {
                $GLOBALS[$k] = $v;
            }
        }
    }

    public function testNoRequestAtAllNeverThrows(): void
    {
        $this->clearRequest();
        $ctx = $this->context();
        foreach ([$ctx->ipAddress(), $ctx->userAgent(), $ctx->requestId()] as $v) {
            $v = self::untyped($v);
            $this->assertTrue($v === null || is_string($v));
        }
        $this->assertSame($ctx->requestId(), $ctx->requestId(), 'request id must be stable outside a web request too');
    }

    public function testIpAddressIsNullOrAValidAddressLiteral(): void
    {
        foreach (['203.0.113.9', '2001:db8::1'] as $ip) {
            $this->clearRequest();
            $this->applyRequest(['ip' => $ip, 'user_agent' => 'Conformance/1.0']);
            $v = $this->context()->ipAddress();
            if ($v !== null) {
                $this->assertLessThanOrEqual(45, strlen($v));
                $this->assertNotFalse(filter_var($v, FILTER_VALIDATE_IP), "ipAddress() returned '$v', which is not an IP address");
            }
        }
        $this->clearRequest();
        $v = $this->context()->ipAddress();
        $this->assertTrue($v === null || filter_var($v, FILTER_VALIDATE_IP) !== false, 'outside a request ipAddress() must be null or a valid address, not a placeholder such as "unknown"');
    }

    public function testUserAgentIsNullOrPrintableText(): void
    {
        $this->clearRequest();
        $this->applyRequest(['ip' => '203.0.113.9', 'user_agent' => 'Mozilla/5.0 (X11; Linux) Conformance/1.0']);
        $ua = self::untyped($this->context()->userAgent());
        $this->assertTrue($ua === null || is_string($ua));
        if ($ua !== null) {
            $this->assertSame(0, preg_match('/[\x00-\x1f\x7f]/', $ua), 'userAgent() contains control characters');
        }
    }

    public function testRequestIdIsNullOrABoundedToken(): void
    {
        foreach ([true, false] as $inRequest) {
            $this->clearRequest();
            if ($inRequest) {
                $this->applyRequest(['ip' => '203.0.113.9', 'user_agent' => 'Conformance/1.0']);
            }
            $id = $this->context()->requestId();
            if ($id !== null) {
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{1,64}$/', $id, 'requestId() must be 1-64 characters of [A-Za-z0-9._:-]');
            }
            $this->addToAssertionCount(1);
        }
    }

    public function testRequestIdIsStableWithinARequest(): void
    {
        $this->clearRequest();
        $this->applyRequest(['ip' => '203.0.113.9', 'user_agent' => 'Conformance/1.0']);
        $a = $this->context();
        $first = $a->requestId();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, $a->requestId(), 'same context, different request id');
        }
        $this->assertSame($first, $this->context()->requestId(), 'a second context in the same request must report the same request id');
    }

    public function testClientSuppliedRequestIdIsNeverTrusted(): void
    {
        $this->clearRequest();
        $this->applyRequest(['ip' => '203.0.113.9', 'user_agent' => 'Conformance/1.0', 'client_request_id' => 'attacker-chosen-id']);
        $id = $this->context()->requestId();
        $this->assertNotSame('attacker-chosen-id', $id, 'a request id chosen by the client (X-Request-ID) must not reach audit rows');
    }

    public function testWorksWithOnlyPartialFacts(): void
    {
        $this->clearRequest();
        $this->applyRequest(['user_agent' => 'Conformance/1.0']);
        $ctx = $this->context();
        $this->assertTrue(self::untyped($ctx->ipAddress()) === null || is_string(self::untyped($ctx->ipAddress())));
        $this->clearRequest();
        $this->applyRequest(['ip' => '203.0.113.9']);
        $ctx = $this->context();
        $this->assertTrue(self::untyped($ctx->userAgent()) === null || is_string(self::untyped($ctx->userAgent())));
    }
}
