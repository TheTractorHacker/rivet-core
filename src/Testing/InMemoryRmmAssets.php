<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Rmm\Contracts\RmmAssetNamesInterface;
use RivetCore\Rmm\Contracts\RmmAssetsInterface;

/**
 * Reference implementation (not API; tests may extend it to build a deliberately broken variant) of {@see RmmAssetsInterface} over arrays.
 *
 * @internal
 */
class InMemoryRmmAssets implements RmmAssetsInterface, RmmAssetNamesInterface
{
    /** @var array<int,array{name:string,client_id:int,location_id:int,serial:?string,model:string,make:string,os:string,type:string,status:string,macs:list<string>,archived:bool}> */
    protected array $assets = [];
    protected int $next = 100;

    /**
     * @param array{name?:string,client_id?:int,serial?:?string,macs?:list<string>,archived?:bool,model?:string,make?:string,os?:string} $a
     */
    public function addAsset(array $a): int
    {
        $this->assets[$this->next] = [
            'name' => $a['name'] ?? 'asset',
            'client_id' => $a['client_id'] ?? 0,
            'location_id' => 0,
            'serial' => $a['serial'] ?? null,
            'model' => $a['model'] ?? '',
            'make' => $a['make'] ?? '',
            'os' => $a['os'] ?? '',
            'type' => 'Laptop',
            'status' => 'Active',
            'macs' => array_map(self::mac(...), $a['macs'] ?? []),
            'archived' => $a['archived'] ?? false,
        ];

        return $this->next++;
    }

    /** @return array{name:string,client_id:int,location_id:int,serial:?string,model:string,make:string,os:string,type:string,status:string,macs:list<string>,archived:bool}|null */
    public function row(int $assetId): ?array
    {
        return $this->assets[$assetId] ?? null;
    }

    public function findBySerial(string $serial, int $limit = 10): array
    {
        return $this->select(static fn (array $a): bool => $a['serial'] === $serial, $limit);
    }

    public function findByMacs(array $macs, int $limit = 20): array
    {
        $want = array_map(self::mac(...), $macs);

        return $this->select(static fn (array $a): bool => array_intersect($a['macs'], $want) !== [], $limit);
    }

    public function findByHostname(string $hostname, int $limit = 10): array
    {
        return $this->select(static fn (array $a): bool => strtolower($a['name']) === strtolower($hostname), $limit);
    }

    public function find(int $assetId): ?array
    {
        $a = $this->assets[$assetId] ?? null;

        return $a === null ? null : ['asset_id' => $assetId, 'client_id' => $a['client_id'], 'archived' => $a['archived']];
    }

    public function createForDevice(array $device, int $clientId, int $locationId): int
    {
        $id = $this->addAsset([
            'name' => $device['hostname'],
            'client_id' => $clientId,
            'serial' => $device['serial'],
            'model' => (string) $device['model'],
            'make' => (string) $device['manufacturer'],
            'os' => 'Windows ' . $device['os_version'],
        ]);
        $this->assets[$id]['location_id'] = $locationId;
        $this->assets[$id]['type'] = stripos($device['os_version'], 'server') !== false ? 'Server' : 'Laptop';

        return $id;
    }

    public function fillBlanks(int $assetId, array $facts): void
    {
        if (!isset($this->assets[$assetId])) {
            return;
        }
        $a = &$this->assets[$assetId];
        if (($a['serial'] === null || $a['serial'] === '') && $facts['serial'] !== null && $facts['serial'] !== '') {
            $a['serial'] = $facts['serial'];
        }
        if ($a['model'] === '' && (string) $facts['model'] !== '') {
            $a['model'] = (string) $facts['model'];
        }
        if ($a['make'] === '' && (string) $facts['manufacturer'] !== '') {
            $a['make'] = (string) $facts['manufacturer'];
        }
        if ($a['os'] === '' && $facts['os'] !== '') {
            $a['os'] = $facts['os'];
        }
    }

    public function moveToClient(int $assetId, int $clientId, int $locationId): void
    {
        if (isset($this->assets[$assetId])) {
            $this->assets[$assetId]['client_id'] = $clientId;
            $this->assets[$assetId]['location_id'] = $locationId;
        }
    }

    public function assetNames(array $assetIds): array
    {
        $out = [];
        foreach ($assetIds as $id) {
            if (isset($this->assets[$id])) {
                $out[$id] = $this->assets[$id]['name'];
            }
        }

        return $out;
    }

    protected static function mac(string $mac): string
    {
        return strtolower(str_replace('-', ':', $mac));
    }

    /**
     * @param callable(array{name:string,client_id:int,location_id:int,serial:?string,model:string,make:string,os:string,type:string,status:string,macs:list<string>,archived:bool}):bool $match
     * @return list<array{asset_id:int, asset_name:string, client_id:int, serial:?string}>
     */
    protected function select(callable $match, int $limit): array
    {
        $out = [];
        foreach ($this->assets as $id => $a) {
            if ($a['archived'] || !$match($a)) {
                continue;
            }
            $out[] = ['asset_id' => $id, 'asset_name' => $a['name'], 'client_id' => $a['client_id'], 'serial' => $a['serial']];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
