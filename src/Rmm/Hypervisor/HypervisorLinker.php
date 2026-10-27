<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Hypervisor;

use RivetCore\Rmm\Contracts\RmmAssetsInterface;
use RivetCore\Rmm\Support\Sql;

/**
 * Suggests which asset a VM is. It never links and never creates anything: the edition shows the suggestions and a person decides. The name comes from the hypervisor (the VM name; for
 * a guest with its agent running, the guest name the edition may pass in); MAC addresses are only used when the caller has them (the collectors do not read VM network configuration, because
 * that would mean reading the VM configuration, which can hold secrets). A match is a hint of one of three strengths: `mac` (high), `hostname` equal to the VM name or the guest name (medium),
 * a name that matches after the domain part is dropped (low).
 *
 * @api
 */
final class HypervisorLinker
{
    public function __construct(private readonly Sql $sql, private readonly RmmAssetsInterface $assets)
    {
    }

    /**
     * @param array<string,mixed> $vm a row of {@see HypervisorReader::vm()}
     * @param list<string> $macs normalised lower-case colon form, when the edition knows them
     * @param string|null $guestName the name the guest reports about itself, when the edition knows it
     * @return list<array{asset_id:int,asset_name:string,client_id:int,matched_by:string,confidence:string,same_client:bool,device_id:?int}>
     */
    public function suggest(array $vm, array $macs = [], ?string $guestName = null, int $limit = 5): array
    {
        $clientId = (int) ($vm['client_id'] ?? 0);
        $out = [];
        $add = function (array $asset, string $by, string $conf) use (&$out, $clientId): void {
            $id = (int) $asset['asset_id'];
            $rank = ['high' => 3, 'medium' => 2, 'low' => 1];
            if (!isset($out[$id]) || $rank[$conf] > $rank[$out[$id]['confidence']]) {
                $out[$id] = ['asset_id' => $id, 'asset_name' => (string) $asset['asset_name'], 'client_id' => (int) $asset['client_id'], 'matched_by' => $by, 'confidence' => $conf,
                    'same_client' => (int) $asset['client_id'] === $clientId, 'device_id' => null];
            }
        };
        $macs = array_values(array_unique(array_filter($macs, static fn (string $m): bool => preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/D', $m) === 1)));
        if ($macs !== []) {
            foreach ($this->assets->findByMacs(array_slice($macs, 0, 16), 10) as $a) {
                $add($a, 'mac', 'high');
            }
        }
        $names = [];
        foreach ([(string) ($vm['name'] ?? ''), (string) $guestName] as $n) {
            $n = trim(HypervisorProtocol::text($n, 100));
            if ($n !== '' && !str_contains($n, ' ')) {
                $names[strtolower($n)] = $n;
                $short = strtolower(explode('.', $n, 2)[0]);
                if ($short !== '' && $short !== strtolower($n)) {
                    $names[$short] = $short;
                }
            }
        }
        foreach ($names as $lower => $n) {
            foreach ($this->assets->findByHostname($n, 5) as $a) {
                $add($a, 'hostname', $lower === strtolower((string) ($vm['name'] ?? '')) || $lower === strtolower((string) $guestName) ? 'medium' : 'low');
            }
        }
        $list = array_values($out);
        usort($list, static function (array $a, array $b): int {
            $rank = ['high' => 3, 'medium' => 2, 'low' => 1];

            return [$b['same_client'], $rank[$b['confidence']], $a['asset_id']] <=> [$a['same_client'], $rank[$a['confidence']], $b['asset_id']];
        });
        // an agent device of the same name tells which asset an enrolled guest already has
        foreach ($list as &$s) {
            $d = $this->sql->one('SELECT device_id FROM endpoint_agent_devices WHERE asset_id = ? AND revoked_at IS NULL AND retired_at IS NULL LIMIT 1', [$s['asset_id']]);
            $s['device_id'] = $d === null ? null : (int) $d['device_id'];
        }
        unset($s);

        return array_slice($list, 0, max(1, min(20, $limit)));
    }
}
