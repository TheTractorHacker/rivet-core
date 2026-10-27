<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

/**
 * The one strict reader of a download address. No `parse_url` (its leniency is the classic way past a host check): one anchored pattern for what is allowed at
 * all, https only, a DNS host name with at least two labels whose last label is not numeric (so no IP address in any notation: dotted, decimal, hex, octal or IPv6),
 * no user information, only the default port, no fragment, no backslash, no whitespace or control character, at most {@see DeployProtocol::URL_MAX} characters.
 * The Go agent applies the same rules.
 *
 * @api
 */
final class SourceUrl
{
    private const RE = '#^https://(?<host>(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?::443)?(?<path>/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*)?(?:\?(?<query>[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]*))?$#D';

    /**
     * The lower-case host of a well-formed address, or null when the address is not acceptable.
     */
    public static function host(mixed $url): ?string
    {
        if (!is_string($url) || $url === '' || strlen($url) > DeployProtocol::URL_MAX || preg_match(self::RE, $url, $m) !== 1) {
            return null;
        }
        $host = strtolower($m['host']);

        return strlen($host) <= 253 ? $host : null;
    }

    /** The address with its host in lower case (what is stored and sent), or null. */
    public static function normalise(mixed $url): ?string
    {
        $host = self::host($url);
        if ($host === null || !is_string($url)) {
            return null;
        }
        // Only the scheme and host are case-insensitive; the rest is kept exactly. The pattern above already guarantees where the host ends.
        $rest = substr($url, strlen('https://') + strlen($host));

        return 'https://' . $host . $rest;
    }
}
