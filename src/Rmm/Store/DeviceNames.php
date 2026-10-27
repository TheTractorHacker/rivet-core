<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

use RivetCore\Rmm\Support\Sql;

/**
 * Adds what only the control database knows to rows a hot store returned: the host name of a device and, for a row stored before the client was recorded, the
 * device's current client. One query for all the devices of a page (phase 2 of #89: a store never joins `endpoint_agent_devices`).
 *
 * @internal
 */
final class DeviceNames
{
    public function __construct(private readonly Sql $sql)
    {
    }

    /**
     * @param list<int> $deviceIds
     * @return array<int,array{hostname:string,client_id:int}>
     */
    public function lookup(array $deviceIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $deviceIds)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->sql->all('SELECT device_id, hostname, client_id FROM endpoint_agent_devices WHERE device_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $out[(int) $r['device_id']] = ['hostname' => (string) $r['hostname'], 'client_id' => (int) $r['client_id']];
            }
        }

        return $out;
    }

    /**
     * The rows with `hostname` added and `client_id` completed.
     *
     * @param list<array<string,mixed>> $rows each with device_id and a client_id that may be null
     * @return list<array<string,mixed>>
     */
    public function hydrate(array $rows): array
    {
        $names = $this->lookup(array_map(static fn (array $r): int => (int) $r['device_id'], $rows));
        foreach ($rows as &$r) {
            $d = $names[(int) $r['device_id']] ?? null;
            $r['hostname'] = $d['hostname'] ?? '';
            $r['client_id'] = $r['client_id'] ?? ($d['client_id'] ?? 0);
        }
        unset($r);

        return $rows;
    }
}
