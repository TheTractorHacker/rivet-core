<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Testing\InMemoryRmmAssets;

/**
 * The reference assets store with an optional named flaw: includes_archived, serial_prefix, mac_strict, mac_duplicates,
 * host_case_sensitive, ignores_limit, find_archived_null, all_laptops, fill_overwrites, fill_nothing, move_all, throws_unknown, bad_shape.
 */
final class FlawedRmmAssets extends InMemoryRmmAssets
{
    public function __construct(private ?string $flaw = null)
    {
    }

    public function addAsset(array $a): int
    {
        $id = parent::addAsset($a);
        if ($this->flaw === 'mac_strict') {
            $this->assets[$id]['macs'] = $a['macs'] ?? []; // stored as typed, compared exactly
        }

        return $id;
    }

    protected function select(callable $match, int $limit): array
    {
        $out = [];
        foreach ($this->assets as $id => $a) {
            if (($a['archived'] && $this->flaw !== 'includes_archived') || !$match($a)) {
                continue;
            }
            $row = ['asset_id' => $id, 'asset_name' => $a['name'], 'client_id' => $a['client_id'], 'serial' => $a['serial']];
            if ($this->flaw === 'bad_shape') {
                $row['extra'] = 1;
            }
            $out[] = $row;
            if (count($out) >= $limit && $this->flaw !== 'ignores_limit') {
                break;
            }
        }

        return $out;
    }

    public function findBySerial(string $serial, int $limit = 10): array
    {
        if ($this->flaw === 'serial_prefix') {
            return $this->select(static fn (array $a): bool => $a['serial'] !== null && str_starts_with($a['serial'], $serial), $limit);
        }

        return parent::findBySerial($serial, $limit);
    }

    public function findByMacs(array $macs, int $limit = 20): array
    {
        if ($this->flaw === 'mac_strict') {
            return $this->select(static fn (array $a): bool => array_intersect($a['macs'], $macs) !== [], $limit);
        }
        if ($this->flaw === 'mac_duplicates') {
            $out = [];
            foreach ($macs as $m) {
                $out = array_merge($out, parent::findByMacs([$m], $limit));
            }

            return $out;
        }

        return parent::findByMacs($macs, $limit);
    }

    public function findByHostname(string $hostname, int $limit = 10): array
    {
        if ($this->flaw === 'host_case_sensitive') {
            return $this->select(static fn (array $a): bool => $a['name'] === $hostname, $limit);
        }

        return parent::findByHostname($hostname, $limit);
    }

    public function find(int $assetId): ?array
    {
        if ($this->flaw === 'find_archived_null' && ($this->assets[$assetId]['archived'] ?? false)) {
            return null;
        }

        return parent::find($assetId);
    }

    public function createForDevice(array $device, int $clientId, int $locationId): int
    {
        $id = parent::createForDevice($device, $clientId, $locationId);
        if ($this->flaw === 'all_laptops') {
            $this->assets[$id]['type'] = 'Laptop';
        }

        return $id;
    }

    public function fillBlanks(int $assetId, array $facts): void
    {
        if ($this->flaw === 'throws_unknown' && !isset($this->assets[$assetId])) {
            throw new \RuntimeException('no such asset');
        }
        if ($this->flaw === 'fill_nothing' || !isset($this->assets[$assetId])) {
            return;
        }
        if ($this->flaw === 'fill_overwrites') {
            $this->assets[$assetId]['serial'] = $facts['serial'];
            $this->assets[$assetId]['model'] = (string) $facts['model'];
            $this->assets[$assetId]['make'] = (string) $facts['manufacturer'];
            $this->assets[$assetId]['os'] = $facts['os'];

            return;
        }
        parent::fillBlanks($assetId, $facts);
    }

    public function moveToClient(int $assetId, int $clientId, int $locationId): void
    {
        if ($this->flaw === 'move_all' && isset($this->assets[$assetId])) {
            $from = $this->assets[$assetId]['client_id'];
            foreach ($this->assets as $id => $a) {
                if ($a['client_id'] === $from) {
                    parent::moveToClient($id, $clientId, $locationId);
                }
            }

            return;
        }
        parent::moveToClient($assetId, $clientId, $locationId);
    }
}
