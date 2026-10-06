<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * Parses and matches the admin-supplied list of internal networks a webhook may reach (see UrlPolicy).
 *
 * Only private/ULA space can be listed (10/8, 172.16/12, 192.168/16, CGNAT 100.64/10, fc00::/7); anything wider or
 * outside that space (public internet, loopback, link-local, ...) is rejected with an error string.
 *
 * @api
 */
final class NetworkList
{
    public const MAX_ENTRIES = 16;
    public const MIN_PREFIX_V4 = 8;
    public const MIN_PREFIX_V6 = 48;

    /** Space an allow-list entry must lie entirely inside. */
    private const PRIVATE_SPACE = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7'];

    /**
     * @param string|list<string> $input comma / space / semicolon / newline separated text, or a list of such strings
     * @return array{networks: list<string>, errors: list<string>}
     */
    public static function parse(string|array $input): array
    {
        $text = is_array($input) ? implode("\n", array_map(static fn ($v): string => (string) $v, $input)) : $input;
        $networks = [];
        $errors = [];
        foreach (preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $cidr = self::normalise($token, $error);
            if ($cidr === null) {
                $errors[] = $error;
                continue;
            }
            if (!in_array($cidr, $networks, true)) {
                $networks[] = $cidr;
            }
        }
        if (count($networks) > self::MAX_ENTRIES) {
            $errors[] = 'Too many networks (maximum ' . self::MAX_ENTRIES . ').';
            $networks = array_slice($networks, 0, self::MAX_ENTRIES);
        }

        return ['networks' => $networks, 'errors' => $errors];
    }

    /**
     * True when $ip lies inside any of the CIDRs. IPv4-mapped IPv6 addresses (::ffff:a.b.c.d) are compared as IPv4.
     * Unparseable CIDRs or addresses never match.
     *
     * @param list<string> $cidrs
     */
    public static function contains(array $cidrs, string $ip): bool
    {
        $bin = self::addressBin($ip);
        if ($bin === null) {
            return false;
        }
        foreach ($cidrs as $cidr) {
            $parsed = self::splitCidr($cidr);
            if ($parsed === null || strlen($parsed[0]) !== strlen($bin)) {
                continue;
            }
            if (self::sameNetwork($bin, $parsed[0], $parsed[1])) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $token, ?string &$error): ?string
    {
        $error = null;
        $parts = explode('/', $token);
        if (count($parts) > 2 || $parts[0] === '') {
            $error = "Invalid network: $token";

            return null;
        }
        $bin = filter_var($parts[0], FILTER_VALIDATE_IP) !== false ? inet_pton($parts[0]) : false;
        if ($bin === false) {
            $error = "Invalid network: $token";

            return null;
        }
        $max = strlen($bin) * 8;
        if (isset($parts[1])) {
            if (!ctype_digit($parts[1]) || strlen($parts[1]) > 3 || (int) $parts[1] > $max) {
                $error = "Invalid network: $token";

                return null;
            }
            $bits = (int) $parts[1];
        } else {
            $bits = $max;
        }
        $min = $max === 32 ? self::MIN_PREFIX_V4 : self::MIN_PREFIX_V6;
        if ($bits < $min) {
            $error = "Network too wide (minimum /$min): $token";

            return null;
        }
        $net = self::mask($bin, $bits);
        $inside = false;
        foreach (self::PRIVATE_SPACE as $space) {
            $s = self::splitCidr($space);
            if ($s !== null && strlen($s[0]) === strlen($net) && $bits >= $s[1] && self::sameNetwork($net, $s[0], $s[1])) {
                $inside = true;
                break;
            }
        }
        if (!$inside) {
            $error = "Not a private network (only 10/8, 172.16/12, 192.168/16, 100.64/10 and fc00::/7 ranges may be allowed): $token";

            return null;
        }

        return inet_ntop($net) . '/' . $bits;
    }

    /** @return array{string,int}|null */
    private static function splitCidr(string $cidr): ?array
    {
        $parts = explode('/', $cidr);
        if (count($parts) !== 2 || !ctype_digit($parts[1]) || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bin = inet_pton($parts[0]);
        if ($bin === false || (int) $parts[1] > strlen($bin) * 8) {
            return null;
        }

        return [$bin, (int) $parts[1]];
    }

    private static function addressBin(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($bin, 12);
        }

        return $bin;
    }

    private static function mask(string $bin, int $bits): string
    {
        $out = '';
        $len = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $keep = max(0, min(8, $bits - $i * 8));
            $out .= chr(ord($bin[$i]) & ((0xff << (8 - $keep)) & 0xff));
        }

        return $out;
    }

    private static function sameNetwork(string $bin, string $net, int $bits): bool
    {
        return self::mask($bin, $bits) === self::mask($net, $bits);
    }
}
