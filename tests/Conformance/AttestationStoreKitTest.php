<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Compliance\AttestationProviderInterface;
use RivetCore\Compliance\AttestationStore;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Testing\AttestationProviderConformanceTestCase;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

/** Core's real AttestationStore on a scratch MySQL/MariaDB (skipped without RIVETCORE_TEST_DB_NAME). */
final class AttestationStoreKitTest extends AttestationProviderConformanceTestCase
{
    private ?DatabaseInterface $db = null;

    private function db(): DatabaseInterface
    {
        if ($this->db === null) {
            $m = ScratchDb::connect();
            if ($m === null) {
                $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
            }
            $this->db = new MysqliDatabase($m);
            (new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock()))->run();
        }

        return $this->db;
    }

    protected function provider(): AttestationProviderInterface
    {
        return new AttestationStore($this->db());
    }

    protected function storeAttestation(string $itemId, string $reviewedOn, ?string $nextDueOn, string $reviewer, ?string $note): void
    {
        (new AttestationStore($this->db()))->record($itemId, null, $reviewer, $reviewedOn, $nextDueOn, $note, new \DateTimeImmutable('2026-12-31'));
    }

    protected function deleteAttestations(array $itemIds): void
    {
        foreach ($itemIds as $id) {
            $this->db()->execute('DELETE FROM compliance_attestations WHERE item_id = ?', [$id]);
        }
    }
}
