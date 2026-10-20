<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Assets;

/**
 * Decides the asset TYPE of a newly enrolled device from what the agent reported. Pure: no database, no clock, no I/O.
 *
 * Output labels (closed list, {@see LABELS}): Laptop, Desktop, Server, Virtual Machine, Hypervisor, Tablet, Other. An edition maps each label to
 * its own asset type name; one that lacks a type uses {@see resolve()} to walk the fallback chain (Hypervisor and Virtual Machine -> Server,
 * Tablet and Desktop and Other -> Laptop), so the worst case is the behaviour of every release before 1.0.0-rc.29.
 *
 * Decision table, first row that applies wins (a missing fact never matches; hostile or out-of-vocabulary values count as missing):
 *
 *  1. role_hints has hypervisor_host                      -> Hypervisor  (a Proxmox or Hyper-V host, even when it is itself nested)
 *  2. is_virtual true, or virtualization not none/empty   -> Virtual Machine (a VM wins over its OS: a Windows Server VM is a Virtual Machine;
 *                                                            the OS and any role stay readable on the asset; an installer choice still overrides)
 *  3. role_hints has domain_controller                    -> Server
 *  4. os_version contains "server" (case-insensitive)     -> Server
 *  5. chassis server, rack_server or blade                -> Server
 *  6. chassis tablet                                      -> Tablet
 *  7. chassis laptop                                      -> Laptop
 *  8. chassis desktop, tower, all_in_one or mini_pc       -> Desktop  (before the battery row: Windows reports a UPS as a battery)
 *  9. has_battery true                                    -> Laptop
 * 10. os is linux and has_battery is false                -> Server   (a Linux box with no battery and no desktop chassis)
 * 11. os is windows and has_battery is false              -> Desktop
 * 12. otherwise (nothing usable was reported)             -> Server if os_version contains "server", else Laptop  (the pre-rc.29 rule)
 *
 * "Other" is never detected; it only comes from an installer's explicit choice.
 *
 * @api
 */
final class DeviceTypeClassifier
{
    public const LABELS = ['Laptop', 'Desktop', 'Server', 'Virtual Machine', 'Hypervisor', 'Tablet', 'Other'];

    /** What an installer's "device type" may be: auto plus the labels. */
    public const INSTALLER_CHOICES = ['auto', ...self::LABELS];

    public const CHASSIS = ['laptop', 'desktop', 'tower', 'all_in_one', 'mini_pc', 'server', 'rack_server', 'blade', 'tablet', 'other'];
    public const VIRTUALIZATION = ['vmware', 'hyperv', 'kvm', 'qemu', 'xen', 'virtualbox', 'parallels', 'lxc', 'container', 'other', 'none'];
    public const ROLE_HINTS = ['hypervisor_host', 'domain_controller'];

    /** Where a label goes when the edition has no asset type of that name. */
    private const FALLBACK = ['Hypervisor' => 'Server', 'Virtual Machine' => 'Server', 'Tablet' => 'Laptop', 'Desktop' => 'Laptop', 'Other' => 'Laptop', 'Server' => 'Laptop'];

    /**
     * @param array<string,mixed> $device any of os, os_version, chassis_type, has_battery, is_virtual, virtualization, role_hints (manufacturer and model are accepted and ignored: the agent already folded them into is_virtual)
     * @return array{type:string,reason:string}
     */
    public static function explain(array $device): array
    {
        $osVersion = is_string($device['os_version'] ?? null) ? mb_strtolower(mb_substr($device['os_version'], 0, 200)) : '';
        $isServerOs = str_contains($osVersion, 'server');
        $os = ($device['os'] ?? null) === 'linux' ? 'linux' : (($device['os'] ?? null) === 'windows' ? 'windows' : null);
        $chassis = self::vocab($device['chassis_type'] ?? null, self::CHASSIS);
        $virt = self::vocab($device['virtualization'] ?? null, self::VIRTUALIZATION);
        $battery = is_bool($device['has_battery'] ?? null) ? $device['has_battery'] : null;
        $virtual = is_bool($device['is_virtual'] ?? null) ? $device['is_virtual'] : null;
        $roles = [];
        if (is_array($device['role_hints'] ?? null)) {
            foreach (array_slice($device['role_hints'], 0, 16) as $r) {
                if (is_string($r) && in_array($r, self::ROLE_HINTS, true)) {
                    $roles[$r] = true;
                }
            }
        }

        if (isset($roles['hypervisor_host'])) {
            return ['type' => 'Hypervisor', 'reason' => 'hypervisor role'];
        }
        if ($virtual === true || ($virt !== null && $virt !== 'none' && $virtual !== false)) {
            return ['type' => 'Virtual Machine', 'reason' => 'virtual' . ($virt !== null && $virt !== 'none' ? ' (' . $virt . ')' : '')];
        }
        if (isset($roles['domain_controller'])) {
            return ['type' => 'Server', 'reason' => 'domain controller role'];
        }
        if ($isServerOs) {
            return ['type' => 'Server', 'reason' => 'server operating system'];
        }
        $row = match ($chassis) {
            'server', 'rack_server', 'blade' => ['Server', 'server chassis'],
            'tablet' => ['Tablet', 'tablet chassis'],
            'laptop' => ['Laptop', 'laptop chassis'],
            'desktop', 'tower', 'all_in_one', 'mini_pc' => ['Desktop', 'desktop chassis'],
            default => null,
        };
        if ($row !== null) {
            return ['type' => $row[0], 'reason' => $row[1]];
        }
        if ($battery === true) {
            return ['type' => 'Laptop', 'reason' => 'has a battery'];
        }
        if ($battery === false && $os === 'linux') {
            return ['type' => 'Server', 'reason' => 'linux without a battery'];
        }
        if ($battery === false && $os === 'windows') {
            return ['type' => 'Desktop', 'reason' => 'no battery'];
        }

        return ['type' => 'Laptop', 'reason' => 'default'];
    }

    /** @param array<string,mixed> $device see {@see explain()} */
    public static function classify(array $device): string
    {
        return self::explain($device)['type'];
    }

    /** True for a label an installer (or the classifier) may produce. */
    public static function validLabel(mixed $v): bool
    {
        return is_string($v) && in_array($v, self::LABELS, true);
    }

    /**
     * Pick the asset type to create: the installer's choice when it is a valid label (not "auto"), else the detected one.
     *
     * @return string one of LABELS
     */
    public static function choose(mixed $hint, string $detected): string
    {
        return self::validLabel($hint) ? (string) $hint : (self::validLabel($detected) ? $detected : 'Laptop');
    }

    /**
     * Map a label onto the names an edition really has: the label itself when available, else its fallback chain, else "Laptop" (always present
     * in every edition since before this feature).
     *
     * @param list<string> $available the edition's asset type names, exact spelling
     */
    public static function resolve(string $label, array $available): string
    {
        $seen = [];
        while (!in_array($label, $available, true) && isset(self::FALLBACK[$label]) && !isset($seen[$label])) {
            $seen[$label] = true;
            $label = self::FALLBACK[$label];
        }

        return $label;
    }

    /**
     * @param list<string> $allowed
     */
    private static function vocab(mixed $v, array $allowed): ?string
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : null;
    }
}
