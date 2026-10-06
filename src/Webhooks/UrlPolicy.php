<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * Vets a webhook endpoint URL (SSRF guard) and returns the vetted target so the caller can PIN the connection to
 * the addresses that were checked (CURLOPT_RESOLVE), which stops DNS rebinding between check and connect.
 *
 * Accepted: http/https, no userinfo, no backslash / control characters / whitespace, and a host that resolves ONLY to
 * public addresses. Rejected addresses: loopback, private (10/8, 172.16/12, 192.168/16, fc00::/7), link-local
 * (169.254/16 incl. cloud metadata 169.254.169.254, fe80::/10), reserved/unspecified, CGNAT 100.64/10, and IPv4 embedded
 * in IPv4-mapped / NAT64 IPv6 forms when the embedded address is itself not public. Also rejected: documentation, benchmarking
 * (198.18/15), IETF-protocol (192.0.0/24) and multicast ranges, and the IPv6 tunnelling forms 6to4 (2002::/16), Teredo
 * (2001::/32) and local-use NAT64 (64:ff9b:1::/48), whose embedded IPv4 cannot be trusted.
 *
 * $allowPrivate=true skips ONLY the address-range test (for editions that deliberately call internal endpoints);
 * the URL shape rules and the pinned target still apply.
 *
 * @api
 */
final class UrlPolicy
{
    /** @var \Closure(string):list<string> */
    private \Closure $resolver;

    /**
     * @param (\Closure(string):list<string>)|null $resolver host name -> IP address strings; defaults to DNS (A + AAAA, then gethostbyname)
     */
    public function __construct(private bool $allowPrivate = false, ?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::dnsResolver(...);
    }

    /**
     * @return array{host:string,port:int,ips:list<string>}|null null when the URL is not allowed
     */
    public function vet(string $url): ?array
    {
        if ($url === '' || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));
        if ($host === '') {
            return null;
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            try {
                $ips = ($this->resolver)($host);
            } catch (\Throwable) {
                return null;
            }
            $ips = array_values(array_filter($ips, static fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        }
        if ($ips === []) {
            return null;
        }
        if (!$this->allowPrivate) {
            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    return null;
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ips' => array_values(array_unique($ips))];
    }

    public function isSafe(string $url): bool
    {
        return $this->vet($url) !== null;
    }

    /** IPv4 ranges that are never public (CIDR). */
    private const NON_PUBLIC_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** IPv6 ranges that are never public (CIDR), including transition mechanisms that can tunnel to IPv4 (6to4, Teredo, local-use NAT64). */
    private const NON_PUBLIC_V6 = [
        '::/128', '::1/128', '100::/64', '2001::/32', '2001:2::/48', '2001:10::/28', '2001:db8::/32', '2002::/16',
        '64:ff9b:1::/48', '::ffff:0:0:0/96', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 4) {
            return !self::inAny($bin, self::NON_PUBLIC_V4);
        }
        if (self::inAny($bin, self::NON_PUBLIC_V6)) {
            return false;
        }
        // IPv4-mapped (::ffff:a.b.c.d), IPv4-compatible (::a.b.c.d), NAT64 (64:ff9b::/96): judge the embedded IPv4
        $embedded = null;
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($bin, str_repeat("\0", 12)) || str_starts_with($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
            $embedded = inet_ntop(substr($bin, 12));
        }

        return $embedded === false || $embedded === null ? true : self::isPublicIp($embedded);
    }

    /** @param list<string> $cidrs */
    private static function inAny(string $bin, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netBin = inet_pton($net);
            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            $rem = $bits % 8;
            if ($rem === 0 || ((ord($bin[$bytes]) ^ ord($netBin[$bytes])) & ((0xff << (8 - $rem)) & 0xff)) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function dnsResolver(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if ($ips === []) {
            $resolved = @gethostbyname($host);
            if ($resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        return $ips;
    }
}
