<?php

declare(strict_types=1);

namespace RivetCore\Support;

use RivetCore\Webhooks\NetworkList;

/**
 * Detects the server's own private IPv4 networks (to suggest a webhook allow-list to an admin).
 * Skips loopback, down interfaces, container/virtual bridges and any address outside private space.
 *
 * @api
 */
final class LocalNetworks
{
    private const SKIP_NAME = '/^(lo|docker\d*|br-.*|veth.*|virbr.*|cni.*|flannel.*|cali.*|vboxnet.*|vmnet.*|tun\d*|tap\d*)$/i';

    /**
     * @param (callable():(array<string,mixed>|false))|null $interfaces defaults to net_get_interfaces()
     * @return list<array{interface:string, cidr:string, address:string}>
     */
    public static function detect(?callable $interfaces = null): array
    {
        if ($interfaces === null) {
            if (!function_exists('net_get_interfaces')) {
                return [];
            }
            $interfaces = 'net_get_interfaces';
        }
        $all = @$interfaces();
        if (!is_array($all)) {
            return [];
        }
        $out = [];
        foreach ($all as $name => $info) {
            $name = (string) $name;
            if (!is_array($info) || empty($info['up']) || preg_match(self::SKIP_NAME, $name) === 1) {
                continue;
            }
            foreach ((array) ($info['unicast'] ?? []) as $addr) {
                if (!is_array($addr) || !isset($addr['address'], $addr['netmask'])) {
                    continue;
                }
                $address = (string) $addr['address'];
                $bits = self::prefixFromMask((string) $addr['netmask']);
                if ($bits === null || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    continue;
                }
                $parsed = NetworkList::parse($address . '/' . $bits);
                if ($parsed['errors'] !== [] || $parsed['networks'] === []) {
                    continue;
                }
                $out[] = ['interface' => $name, 'cidr' => $parsed['networks'][0], 'address' => $address];
            }
        }

        return $out;
    }

    private static function prefixFromMask(string $mask): ?int
    {
        if (filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $n = ip2long($mask);
        if ($n === false) {
            return null;
        }
        $bin = str_pad(decbin($n & 0xffffffff), 32, '0', STR_PAD_LEFT);

        return preg_match('/^1*0*$/', $bin) === 1 ? substr_count($bin, '1') : null;
    }
}
