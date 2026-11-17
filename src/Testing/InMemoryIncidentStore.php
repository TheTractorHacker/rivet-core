<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Monitoring\Inbound\Incident;
use RivetCore\Monitoring\Inbound\IncidentStoreInterface;

/**
 * Reference implementation (not API) of {@see IncidentStoreInterface} over an array. Stores copies (through toArray/fromArray), like a database would.
 *
 * @internal
 */
class InMemoryIncidentStore implements IncidentStoreInterface
{
    /** @var array<string,array<string,mixed>> */
    protected array $rows = [];
    /** @var array<string,bool> */
    protected array $locks = [];
    /** @var int number of put() calls (for assertions) */
    public int $puts = 0;

    public function get(string $source, string $monitorKey): ?Incident
    {
        $row = $this->rows[self::key($source, $monitorKey)] ?? null;

        return $row === null ? null : Incident::fromArray($row);
    }

    public function put(Incident $incident): void
    {
        $this->puts++;
        $this->rows[self::key($incident->source, $incident->monitorKey)] = $incident->toArray();
    }

    public function locked(string $source, string $monitorKey, \Closure $fn): mixed
    {
        $k = self::key($source, $monitorKey);
        if (isset($this->locks[$k])) {
            throw new \LogicException('Re-entrant lock on the same incident key.');
        }
        $this->locks[$k] = true;
        try {
            return $fn();
        } finally {
            unset($this->locks[$k]);
        }
    }

    /** @return list<Incident> */
    public function all(): array
    {
        return array_values(array_map(Incident::fromArray(...), $this->rows));
    }

    private static function key(string $source, string $monitorKey): string
    {
        return $source . "\0" . $monitorKey;
    }
}
