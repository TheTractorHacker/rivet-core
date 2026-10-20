<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Presentation;

/**
 * The short device header both editions render: name, state, OS, a few facts and AT MOST FOUR chips (cpu, ram, disk, uptime, in that order), plus the full volume list for
 * a modal. Pure: it is built from the array {@see \RivetCore\Rmm\Read\RmmReadModel::detail()} returns (no query, no clock), so the same input gives the same header.
 *
 * The chip levels use the same limits as the starter alerting checks: warn at 85 % CPU or memory and 80 % disk, crit at 95 % and 90 %.
 *
 * @api
 */
final class DeviceHeader
{
    /** Same shape as `params.thresholds` ({@see \RivetCore\Rmm\Alerting\Thresholds}); "gte" so the limit itself already counts. */
    public const THRESHOLDS = [
        'cpu' => ['warn' => ['op' => 'gte', 'value' => 85], 'crit' => ['op' => 'gte', 'value' => 95]],
        'ram' => ['warn' => ['op' => 'gte', 'value' => 85], 'crit' => ['op' => 'gte', 'value' => 95]],
        'disk' => ['warn' => ['op' => 'gte', 'value' => 80], 'crit' => ['op' => 'gte', 'value' => 90]],
    ];

    /**
     * @param array<string,mixed> $detail the read model's device detail
     * @param array<string,array<string,mixed>>|null $thresholds override of {@see self::THRESHOLDS} (keys cpu, ram, disk; a missing key keeps the default)
     * @return array{name:string,status:string,os:array{family:string,label:string},meta:array<string,mixed>,chips:list<array<string,mixed>>,volumes:list<array<string,mixed>>,has_desktop:?bool}
     */
    public static function build(array $detail, ?array $thresholds = null): array
    {
        $thr = ($thresholds ?? []) + self::THRESHOLDS;
        $inv = is_array($detail['inventory'] ?? null) ? $detail['inventory'] : [];
        $met = is_array($detail['metrics'] ?? null) ? $detail['metrics'] : [];
        $family = self::family($inv, (string) ($detail['os_version'] ?? ''));
        $osVersion = self::str($detail['os_version'] ?? ($inv['os_version'] ?? null));
        $vol = DeviceVolumes::build(is_array($inv['disks'] ?? null) ? array_values($inv['disks']) : [], is_array($met['disk'] ?? null) ? array_values($met['disk']) : [], $family, $thr);

        $chips = [];
        if (($cpu = self::pct($met['cpu_pct'] ?? null)) !== null) {
            $chips[] = ['key' => 'cpu', 'pct' => $cpu, 'level' => DeviceVolumes::level($cpu, $thr['cpu'])];
        }
        if (($ram = self::pct($met['mem_pct'] ?? null)) !== null) {
            $chips[] = ['key' => 'ram', 'pct' => $ram, 'level' => DeviceVolumes::level($ram, $thr['ram'])];
        }
        if (($p = $vol['primary']) !== null) {
            $chips[] = ['key' => 'disk', 'label' => $p['label'], 'used_pct' => $p['used_pct'], 'free_bytes' => $p['free_bytes'], 'total_bytes' => $p['total_bytes'], 'level' => $p['level'],
                'pool' => $p['pool'] !== null, 'more' => $vol['more']];
        }
        if (is_int($detail['uptime_s'] ?? null) && $detail['uptime_s'] >= 0) {
            $chips[] = ['key' => 'uptime', 'seconds' => $detail['uptime_s']];
        }

        return [
            'name' => self::str($detail['hostname'] ?? null) ?? '',
            'status' => (string) ($detail['status'] ?? 'unknown'),
            'os' => ['family' => $family, 'label' => $osVersion ?? ucfirst($family)],
            'meta' => ['os_version' => $osVersion, 'arch' => self::str($detail['arch'] ?? null), 'last_seen_at' => $detail['last_checkin_at'] ?? null,
                'agent_version' => self::str($detail['agent_version'] ?? null), 'ring' => self::str($detail['ring'] ?? null)],
            'chips' => $chips,
            'volumes' => $vol['volumes'],
            'has_desktop' => is_bool($inv['desktop_session'] ?? null) ? $inv['desktop_session'] : null,
        ];
    }

    /** @param array<string,mixed> $inv */
    public static function family(array $inv, string $osVersion = ''): string
    {
        $os = strtolower(trim((string) ($inv['os'] ?? '')));
        $v = strtolower($osVersion . ' ' . (string) ($inv['os_version'] ?? ''));
        if ($os === 'windows' || ($os === '' && str_contains($v, 'windows'))) {
            return 'windows';
        }
        if ($os === 'linux' || ($os === '' && preg_match('/linux|ubuntu|debian|proxmox|centos|rhel|fedora|alma|rocky/', $v) === 1)) {
            return 'linux';
        }

        return 'other';
    }

    private static function pct(mixed $v): ?float
    {
        return is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)) ? round(max(0.0, min(100.0, (float) $v)), 1) : null;
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 200) : null;
    }
}
