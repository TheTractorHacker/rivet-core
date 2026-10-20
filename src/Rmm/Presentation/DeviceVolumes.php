<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Presentation;

use RivetCore\Rmm\Alerting\Thresholds;

/**
 * The volume list of a device and the choice of the ONE volume the header shows. Pure: it reads the arrays a check-in stored (inventory `disks`, metrics `disk`), nothing else.
 *
 * Decision table, in order (documented in docs/modules/rmm.md, "Device header view-model"):
 *  1. Pseudo mounts never count as a volume to show: file systems tmpfs, devtmpfs, ramfs, squashfs, overlay, aufs, proc, sysfs, cgroup*, devpts, efivarfs, fuse.*; mount paths
 *     under /run, /sys, /proc, /dev, /snap, /var/lib/docker, /var/lib/containers, /var/lib/kubelet; a size of zero; on Windows anything that is not a drive letter, and a
 *     volume labelled Recovery, System Reserved or EFI. They stay in the list with `pseudo: true`.
 *  2. /boot/efi, /boot/firmware and /efi are `minor`: they are chosen only when nothing else is left.
 *  3. Volumes of one pool (ZFS datasets of the same pool, btrfs subvolumes of one device, bind mounts of one device) collapse into ONE entry: `pool` with `children`.
 *  4. The header volume is the system one (C: on Windows, / on Linux; the pool holding it when it belongs to one), else the fullest.
 *
 * @api
 */
final class DeviceVolumes
{
    public const MAX_VOLUMES = 200;
    private const PSEUDO_FS = ['tmpfs', 'devtmpfs', 'ramfs', 'squashfs', 'overlay', 'aufs', 'proc', 'sysfs', 'devpts', 'efivarfs', 'securityfs', 'debugfs', 'tracefs', 'configfs', 'mqueue', 'hugetlbfs', 'pstore', 'bpf', 'nsfs', 'autofs', 'binfmt_misc', 'fusectl'];
    private const PSEUDO_PREFIX = ['/run', '/sys', '/proc', '/dev', '/snap', '/var/lib/docker', '/var/lib/containers', '/var/lib/kubelet', '/var/snap'];
    private const MINOR_MOUNTS = ['/boot/efi', '/boot/firmware', '/efi'];

    /**
     * @param list<mixed> $inventoryDisks inventory `disks`: {mount, total_bytes, free_bytes, fs, source?}
     * @param list<mixed> $metricDisks metrics `disk`: {mount, used_pct, free_bytes?, total_bytes?} (fresher than the inventory; wins on the figures)
     * @param array<string,array<string,mixed>> $thresholds {@see DeviceHeader::THRESHOLDS}
     * @return array{volumes:list<array<string,mixed>>,units:list<array<string,mixed>>,primary:?array<string,mixed>,more:int}
     */
    public static function build(array $inventoryDisks, array $metricDisks, string $family, array $thresholds): array
    {
        $vols = self::merge($inventoryDisks, $metricDisks, $family, $thresholds);
        self::group($vols);
        $volumes = array_slice($vols, 0, self::MAX_VOLUMES);

        // Units: one per pool, one per ungrouped volume. Pseudo volumes are not units.
        $units = [];
        $seenPool = [];
        foreach ($volumes as $v) {
            if ($v['pseudo']) {
                continue;
            }
            if (isset($v['pool'])) {
                $p = $v['pool']['name'];
                if (isset($seenPool[$p])) {
                    $units[$seenPool[$p]]['members'][] = $v;
                    continue;
                }
                $seenPool[$p] = count($units);
            }
            $units[] = ['pool' => $v['pool'] ?? null, 'members' => [$v]];
        }
        $units = array_map(static fn (array $u): array => self::unit($u, $thresholds), $units);
        $major = array_values(array_filter($units, static fn (array $u): bool => !$u['minor']));
        $candidates = $major !== [] ? $major : $units;
        $primary = null;
        foreach ($candidates as $u) {
            if ($u['system']) {
                $primary = $u;
                break;
            }
        }
        if ($primary === null) {
            foreach ($candidates as $u) {
                if ($primary === null || ($u['used_pct'] ?? -1) > ($primary['used_pct'] ?? -1)) {
                    $primary = $u;
                }
            }
        }

        // The list for a modal: pools once with their children, then loose volumes.
        $list = [];
        $emitted = [];
        foreach ($volumes as $v) {
            if (isset($v['pool'])) {
                $p = $v['pool']['name'];
                if (isset($emitted[$p])) {
                    continue;
                }
                $emitted[$p] = true;
                $members = array_values(array_filter($volumes, static fn (array $m): bool => ($m['pool']['name'] ?? null) === $p));
                $pool = $v['pool'];
                foreach ($units as $u) {
                    if (($u['pool']['name'] ?? null) === $p) {
                        $pool = $u['pool'];
                    }
                }
                $pool['children'] = array_map(static fn (array $m): array => self::publicVolume($m, false), $members);
                $pool['count'] = count($members);
                $list[] = ['mount' => $members[0]['mount'], 'label' => $p, 'fs_type' => $v['fs_type'], 'used_pct' => $pool['used_pct'], 'free_bytes' => $pool['free_bytes'], 'total_bytes' => $pool['total_bytes'],
                    'level' => $pool['level'], 'pseudo' => false, 'pool' => $pool];
                continue;
            }
            $list[] = self::publicVolume($v, true);
        }

        return ['volumes' => $list, 'units' => $units, 'primary' => $primary, 'more' => max(0, count($candidates) - ($primary === null ? 0 : 1))];
    }

    /**
     * @param list<mixed> $inv
     * @param list<mixed> $met
     * @param array<string,array<string,mixed>> $thr
     * @return list<array<string,mixed>>
     */
    private static function merge(array $inv, array $met, string $family, array $thr): array
    {
        $byMount = [];
        foreach ($inv as $d) {
            if (is_array($d) && ($m = self::text($d['mount'] ?? null, 200)) !== '') {
                $byMount[$m] = ['mount' => $m, 'total' => self::int($d['total_bytes'] ?? null), 'free' => self::int($d['free_bytes'] ?? null), 'fs' => strtolower(self::text($d['fs'] ?? null, 20)),
                    'source' => self::text($d['source'] ?? null, 200), 'label' => self::text($d['label'] ?? null, 100), 'used_pct' => null];
            }
        }
        foreach ($met as $d) {
            if (!is_array($d) || ($m = self::text($d['mount'] ?? null, 200)) === '') {
                continue;
            }
            $r = $byMount[$m] ?? ['mount' => $m, 'total' => null, 'free' => null, 'fs' => '', 'source' => '', 'label' => '', 'used_pct' => null];
            foreach (['total' => 'total_bytes', 'free' => 'free_bytes'] as $k => $src) {
                if (self::int($d[$src] ?? null) !== null) {
                    $r[$k] = self::int($d[$src]);
                }
            }
            $r['used_pct'] = is_numeric($d['used_pct'] ?? null) ? max(0.0, min(100.0, (float) $d['used_pct'])) : null;
            $byMount[$m] = $r;
        }
        $out = [];
        foreach (array_slice(array_values($byMount), 0, 1000) as $r) {
            if ($r['total'] !== null && $r['free'] !== null && $r['free'] > $r['total']) {
                $r['free'] = $r['total'];
            }
            $pct = $r['total'] !== null && $r['total'] > 0 && $r['free'] !== null ? 100 * ($r['total'] - $r['free']) / $r['total'] : $r['used_pct'];
            $pct = $pct === null ? null : round($pct, 1);
            $mount = $r['mount'];
            $lbl = $r['label'];
            $pseudo = $r['total'] === 0 || in_array($r['fs'], self::PSEUDO_FS, true) || str_starts_with($r['fs'], 'cgroup') || str_starts_with($r['fs'], 'fuse.');
            if ($family === 'windows') {
                $pseudo = $pseudo || preg_match('/^[A-Za-z]:\\\\?$/', $mount) !== 1 || preg_match('/recovery|system reserved|^efi/i', $lbl) === 1;
            } else {
                foreach (self::PSEUDO_PREFIX as $p) {
                    $pseudo = $pseudo || $mount === $p || str_starts_with($mount, $p . '/');
                }
            }
            $out[] = ['mount' => $mount, 'label' => $lbl !== '' ? $lbl : $mount, 'fs_type' => $r['fs'] !== '' ? $r['fs'] : null, 'source' => $r['source'], 'used_pct' => $pct,
                'free_bytes' => $r['free'], 'total_bytes' => $r['total'], 'level' => $pct === null ? null : self::level($pct, $thr['disk']), 'pseudo' => $pseudo,
                'minor' => in_array($mount, self::MINOR_MOUNTS, true), 'system' => $mount === '/' || preg_match('/^[Cc]:\\\\?$/', $mount) === 1];
        }

        return $out;
    }

    /**
     * Give pool members their `pool` {name, kind}. With a `source` it is exact (ZFS: the first path segment of rpool/data/x; btrfs and bind mounts: the device).
     * Without one (an older agent) the fallback is: ZFS volumes that share the same free figure, or any volumes that share both free and total.
     *
     * @param list<array<string,mixed>> $vols
     */
    private static function group(array &$vols): void
    {
        $groups = [];
        foreach ($vols as $i => $v) {
            if ($v['pseudo']) {
                continue;
            }
            $src = (string) $v['source'];
            $fs = (string) $v['fs_type'];
            if ($fs === 'zfs' && $src !== '' && preg_match('#^[A-Za-z0-9_.:-]+(/|$)#', $src) === 1) {
                $groups['zfs:' . explode('/', $src)[0]][] = $i;
            } elseif ($src !== '' && $src[0] === '/' && $fs !== '' && !in_array($fs, ['vfat', 'exfat'], true)) {
                $groups['dev:' . $src][] = $i;
            } elseif ($v['free_bytes'] !== null && $v['free_bytes'] > 0) {
                $groups[($fs === 'zfs' ? 'zfs?:' : 'fig:' . $v['total_bytes'] . ':') . $v['free_bytes']][] = $i;
            }
        }
        foreach ($groups as $key => $idx) {
            if (count($idx) < 2) {
                continue;
            }
            $kind = str_starts_with($key, 'zfs') ? 'zfs' : (str_starts_with($key, 'dev:') ? 'device' : 'shared');
            $name = match (true) {
                str_starts_with($key, 'zfs:') => substr($key, 4),
                $kind === 'zfs' => self::guessPool(array_map(static fn (int $i): string => (string) $vols[$i]['mount'], $idx)),
                str_starts_with($key, 'dev:') => substr($key, 4),
                default => (string) $vols[$idx[0]]['mount'],
            };
            foreach ($idx as $i) {
                $vols[$i]['pool'] = ['name' => $name, 'kind' => $kind];
            }
        }
    }

    /** @param list<string> $mounts */
    private static function guessPool(array $mounts): string
    {
        foreach ($mounts as $m) {
            if (preg_match('#^/([A-Za-z0-9_.-]+)$#', $m, $g) === 1 && $g[1] !== 'var') {
                $others = array_filter($mounts, static fn (string $o): bool => str_starts_with($o, $m . '/'));
                if ($others !== []) {
                    return $g[1];
                }
            }
        }

        return 'zfs';
    }

    /**
     * @param array{pool:?array<string,mixed>,members:list<array<string,mixed>>} $u
     * @param array<string,array<string,mixed>> $thr
     * @return array<string,mixed>
     */
    private static function unit(array $u, array $thr): array
    {
        $m = $u['members'];
        $system = false;
        $minor = true;
        foreach ($m as $v) {
            $system = $system || $v['system'];
            $minor = $minor && $v['minor'];
        }
        if ($u['pool'] === null) {
            $v = $m[0];

            return ['pool' => null, 'label' => $v['label'], 'fs_type' => $v['fs_type'], 'used_pct' => $v['used_pct'], 'free_bytes' => $v['free_bytes'], 'total_bytes' => $v['total_bytes'], 'level' => $v['level'],
                'system' => $system, 'minor' => $minor, 'members' => 1];
        }
        // A pool shares its free space: used = sum of the members' used, free = the shared figure (the smallest reported, never over-stated).
        $used = 0;
        $free = null;
        foreach ($m as $v) {
            $used += $v['total_bytes'] !== null && $v['free_bytes'] !== null ? $v['total_bytes'] - $v['free_bytes'] : 0;
            if ($v['free_bytes'] !== null) {
                $free = $free === null ? $v['free_bytes'] : min($free, $v['free_bytes']);
            }
        }
        if ($u['pool']['kind'] !== 'zfs') {   // bind mounts and subvolumes of one device report the same figures: do not add them up
            $tot = max(array_map(static fn (array $v): int => $v['total_bytes'] ?? 0, $m));
            $total = $tot > 0 ? $tot : null;
        } else {
            $total = $free !== null ? $used + $free : null;
        }
        $pct = $total !== null && $total > 0 && $free !== null ? round(100 * ($total - $free) / $total, 1) : null;
        $level = $pct === null ? null : self::level($pct, $thr['disk']);
        $pool = ['name' => $u['pool']['name'], 'kind' => $u['pool']['kind'], 'used_pct' => $pct, 'free_bytes' => $free, 'total_bytes' => $total, 'level' => $level];

        return ['pool' => $pool, 'label' => $pool['name'], 'fs_type' => $m[0]['fs_type'], 'used_pct' => $pct, 'free_bytes' => $free, 'total_bytes' => $total, 'level' => $level,
            'system' => $system, 'minor' => $minor, 'members' => count($m)];
    }

    /**
     * @param array<string,mixed> $v
     * @return array<string,mixed>
     */
    private static function publicVolume(array $v, bool $withPool): array
    {
        $o = ['mount' => $v['mount'], 'label' => $v['label'], 'fs_type' => $v['fs_type'], 'used_pct' => $v['used_pct'], 'free_bytes' => $v['free_bytes'], 'total_bytes' => $v['total_bytes'], 'level' => $v['level'], 'pseudo' => $v['pseudo']];
        if ($withPool && isset($v['pool'])) {
            $o['pool'] = $v['pool'];
        }

        return $o;
    }

    /** @param array<string,mixed> $t */
    public static function level(float $value, array $t): string
    {
        return Thresholds::rawTier($value, $t);
    }

    private static function text(mixed $v, int $max): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';
        $v = trim($v);

        return mb_substr($v, 0, $max);
    }

    private static function int(mixed $v): ?int
    {
        return is_int($v) || (is_string($v) && ctype_digit($v)) || (is_float($v) && $v >= 0 && $v < 9.0e18) ? max(0, (int) $v) : null;
    }
}
