<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\RmmAuditInterface;

/**
 * Conformance kit for {@see RmmAuditInterface}. Optionally the edition says how to read what was recorded.
 *
 * Checks: record() never throws for ordinary, empty, long, unicode and hostile text or for client/entity id 0; when the
 * records are observable, what was recorded is stored with its action, description and ids.
 *
 * @api
 */
abstract class RmmAuditConformanceTestCase extends TestCase
{
    abstract protected function audit(): RmmAuditInterface;

    /**
     * The records for this entity id, or null when the edition's log cannot be read back.
     *
     * @return list<array{action:string, description:string, client_id:int}>|null
     */
    protected function recorded(int $entityId): ?array
    {
        return null;
    }

    public function testRecordNeverThrows(): void
    {
        foreach ([
            ['Enrolled', 'Device enrolled', 1, 2],
            ['', '', 0, 0],
            [str_repeat('A', 500), str_repeat('d', 20000), 1, 1],
            ["Jøb \u{1F680}", "'; DROP TABLE logs; -- \0 \n", 1, 1],
        ] as [$action, $description, $client, $entity]) {
            $this->audit()->record($action, $description, $client, $entity);
        }
        $this->addToAssertionCount(1);
    }

    public function testWhatWasRecordedCanBeReadBack(): void
    {
        $entity = random_int(1_000_000, 2_000_000_000);
        $this->audit()->record('Job Submitted', 'script sha256 abc', 12, $entity);
        $rows = $this->recorded($entity);
        if ($rows === null) {
            $this->markTestSkipped('The edition log cannot be read back.');
        }
        $this->assertCount(1, $rows);
        $this->assertSame('Job Submitted', $rows[0]['action']);
        $this->assertSame('script sha256 abc', $rows[0]['description']);
        $this->assertSame(12, $rows[0]['client_id']);
    }
}
