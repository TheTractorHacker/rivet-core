<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Rmm\Contracts\RmmAuditInterface;

/**
 * Reference implementation (not API; tests may extend it to build a deliberately broken variant) of {@see RmmAuditInterface}: remembers what was recorded.
 *
 * @internal
 */
class InMemoryRmmAudit implements RmmAuditInterface
{
    /** @var list<array{action:string, description:string, client_id:int, entity_id:int}> */
    protected array $records = [];

    public function record(string $action, string $description, int $clientId, int $entityId): void
    {
        $this->records[] = ['action' => $action, 'description' => $description, 'client_id' => $clientId, 'entity_id' => $entityId];
    }

    /** @return list<array{action:string, description:string, client_id:int, entity_id:int}> */
    public function records(): array
    {
        return $this->records;
    }
}
