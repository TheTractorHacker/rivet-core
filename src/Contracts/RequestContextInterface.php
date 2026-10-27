<?php

declare(strict_types=1);

namespace RivetCore\Contracts;

/**
 * Per-request facts Core may record (audit trail, rate limits) without ever
 * reading superglobals. The edition supplies a real implementation; CLI/cron
 * callers can use NullRequestContext.
 *
 * Contract (checked by Testing\RequestContextConformanceTestCase): every method returns null when the fact is unknown
 * (CLI, cron) and never throws; the ip address is null or an IP literal of at most 45 characters; the request id is null or
 * 1-64 characters of [A-Za-z0-9._:-], assigned by the server, stable for the whole request, and never a value the client
 * chose (an X-Request-ID header is not trusted); the user agent is null or text without control characters (Core clamps its length).
 *
 * @api
 */
interface RequestContextInterface
{
    public function ipAddress(): ?string;

    public function userAgent(): ?string;

    public function requestId(): ?string;
}
