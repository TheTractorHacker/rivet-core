<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

use RivetCore\Rmm\Contracts\PackageSourcePolicyInterface;

/**
 * Core's package source policy: a download address is allowed when it is a well-formed https address ({@see SourceUrl}) and its host is, as a whole, one of the
 * host names the administrator listed. An empty list allows nothing. Exact names only: no wildcard, no suffix match (`example.com` does not allow `cdn.example.com`
 * or `example.com.evil.test`).
 *
 * @api
 */
final class AllowedHostsPolicy implements PackageSourcePolicyInterface
{
    /**
     * @param \Closure():list<string> $hosts the listed host names (lower case)
     */
    public function __construct(private readonly \Closure $hosts)
    {
    }

    public function allows(string $url, int $clientId): bool
    {
        $host = SourceUrl::host($url);
        if ($host === null) {
            return false;
        }
        try {
            $list = ($this->hosts)();
        } catch (\Throwable) {
            return false;   // when in doubt, nothing is downloaded
        }

        return in_array($host, $list, true);
    }
}
