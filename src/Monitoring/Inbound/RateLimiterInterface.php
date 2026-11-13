<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * Hook for the edition's rate limiter (e.g. Core's token bucket, a fail2ban-style table, or the web server). Called once per request,
 * before authentication, so guessing the secret is throttled too. Return false to answer 429.
 *
 * @api
 */
interface RateLimiterInterface
{
    /** @param string $key "inbound-monitoring:" . the remote address */
    public function allow(string $key): bool;
}
