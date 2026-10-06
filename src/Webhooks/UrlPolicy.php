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
 * in IPv4-mapped / NAT64 IPv6 forms when the embedded address is itself not public.
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
            return !self::isCgnat($bin);
        }
        // IPv6: unspecified, loopback, ULA, link-local (belt and braces on top of the filter flags)
        if ($bin === str_repeat("\0", 16) || $bin === str_repeat("\0", 15) . "\x01") {
            return false;
        }
        $b0 = ord($bin[0]);
        $b1 = ord($bin[1]);
        if (($b0 & 0xfe) === 0xfc || ($b0 === 0xfe && ($b1 & 0xc0) === 0x80) || ($b0 === 0xfe && ($b1 & 0xc0) === 0xc0) || $b0 === 0xff) {
            return false;
        }
        // IPv4-mapped (::ffff:a.b.c.d), IPv4-compatible (::a.b.c.d), NAT64 (64:ff9b::/96): judge the embedded IPv4
        $embedded = null;
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($bin, str_repeat("\0", 12)) || str_starts_with($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
            $embedded = inet_ntop(substr($bin, 12));
        }

        return $embedded === false || $embedded === null ? true : self::isPublicIp($embedded);
    }

    private static function isCgnat(string $bin4): bool
    {
        return ord($bin4[0]) === 100 && (ord($bin4[1]) & 0xc0) === 0x40;
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
