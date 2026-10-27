<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Testing\RmmAuditConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedRmmAudit;

/** The kit against the in-memory audit (and the harness target for the audit mutants). */
final class RmmAuditKitTest extends RmmAuditConformanceTestCase
{
    use Flaw;

    private ?FlawedRmmAudit $audit = null;

    private function store(): FlawedRmmAudit
    {
        return $this->audit ??= new FlawedRmmAudit(self::$flaw);
    }

    protected function audit(): RmmAuditInterface
    {
        return $this->store();
    }

    protected function recorded(int $entityId): ?array
    {
        $out = [];
        foreach ($this->store()->records() as $r) {
            if ($r['entity_id'] === $entityId) {
                $out[] = ['action' => $r['action'], 'description' => $r['description'], 'client_id' => $r['client_id']];
            }
        }

        return $out;
    }
}
