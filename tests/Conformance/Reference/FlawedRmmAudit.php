<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Testing\InMemoryRmmAudit;

/** The reference audit with an optional named flaw: throws_empty, swaps_ids, truncates. */
final class FlawedRmmAudit extends InMemoryRmmAudit
{
    public function __construct(private ?string $flaw = null)
    {
    }

    public function record(string $action, string $description, int $clientId, int $entityId): void
    {
        if ($this->flaw === 'throws_empty' && ($action === '' || $description === '')) {
            throw new \InvalidArgumentException('empty');
        }
        if ($this->flaw === 'swaps_ids') {
            [$clientId, $entityId] = [$entityId, $clientId];
        }
        if ($this->flaw === 'truncates') {
            $description = substr($description, 0, 5);
        }
        parent::record($action, $description, $clientId, $entityId);
    }
}
