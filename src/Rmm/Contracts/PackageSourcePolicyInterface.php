<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * Optional: the say an edition (or Core's own allow-list) has over WHERE a device may be told to download an installer from (Phase 5, `software_deploy`).
 * Core asks it twice: when an administrator saves a package version, and again when a job is built for a device, so removing a host from the list stops
 * deployments that were already assigned. The default is {@see \RivetCore\Rmm\Deploy\AllowedHostsPolicy}: exact host names the administrator listed, https only.
 *
 * What every policy must do (the conformance kit {@see \RivetCore\Testing\PackageSourcePolicyConformanceTestCase} checks it): answer a bool and never throw, whatever
 * the text; answer the same to the same question; allow only `https`, never a URL with user information, an IP address (any notation), a port other than 443, a
 * fragment, a backslash, whitespace or a control character; compare the host as a whole and case-insensitively (never a suffix, a prefix or a substring).
 *
 * @api
 */
interface PackageSourcePolicyInterface
{
    /**
     * @param string $url the full download address
     * @param int $clientId the client the device belongs to (0 when the question is not about a client, e.g. when a version is saved)
     */
    public function allows(string $url, int $clientId): bool;
}
