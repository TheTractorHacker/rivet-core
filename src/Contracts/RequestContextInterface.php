<?php

declare(strict_types=1);

namespace RivetCore\Contracts;

/**
 * Per-request facts Core may record (audit trail, rate limits) without ever
 * reading superglobals. The edition supplies a real implementation; CLI/cron
 * callers can use NullRequestContext.
 */
interface RequestContextInterface
{
    public function ipAddress(): ?string;

    public function userAgent(): ?string;

    public function requestId(): ?string;
}
