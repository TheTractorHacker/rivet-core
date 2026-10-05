<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\AttestationStore;
use RivetCore\Compliance\ClientChecklist;
use RivetCore\Compliance\Framework;
use RivetCore\Compliance\SnapshotStore;
use RivetCore\Compliance\SubjectCompliance;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

final class SubjectComplianceTest extends TestCase
{
    private MysqliDatabase $db;
    private SubjectCompliance $svc;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        (new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock()))->run();
        foreach (['compliance_attestations', 'compliance_snapshots', 'compliance_subjects'] as $t) {
            $this->db->execute("DELETE FROM $t");
        }
        $this->svc = new SubjectCompliance($this->db, new FixedClock());
    }

    public function testChecklistIsWellFormed(): void
    {
        $ids = ClientChecklist::ids();
        self::assertSame($ids, array_values(array_unique($ids)));
        foreach (ClientChecklist::items() as $i) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]{1,64}$/', $i->id);
            self::assertNotEmpty($i->controls);
        }
        $hipaa = ClientChecklist::forFrameworks([Framework::HIPAA]);
        self::assertContains('baa_in_place', array_map(fn ($i) => $i->id, $hipaa));
        self::assertNotContains('pci_scope_validation', array_map(fn ($i) => $i->id, $hipaa));
        foreach ($hipaa as $i) {
            self::assertSame([Framework::HIPAA], array_keys($i->controls), 'controls trimmed to the chosen framework');
        }
    }

    public function testFrameworksAndScoringOnlyCountWhatIsChosen(): void
    {
        self::assertSame([], $this->svc->frameworks(7));
        self::assertSame([Framework::ISO27001, Framework::HIPAA], $this->svc->setFrameworks(7, ['hipaa', 'bogus', 'iso27001', 'hipaa']));
        $a = $this->svc->assess(7);
        self::assertSame(0, $a->summaries[Framework::PCI]['items']);
        self::assertNull($a->summaries[Framework::PCI]['score']);
        self::assertSame(0.0, $a->summaries['all']['score'], 'nothing reviewed yet');
        $this->svc->record(7, 'baa_in_place', 1, 'Pat', '2025-12-01', null, 'signed copy in vault');
        $a = $this->svc->assess(7);
        self::assertGreaterThan(0.0, $a->summaries[Framework::HIPAA]['score']);
        self::assertSame(1, $a->summaries[Framework::HIPAA]['manual_current']);
        $this->svc->setFrameworks(7, ['pci']);
        self::assertSame(0, $this->svc->assess(7)->summaries[Framework::HIPAA]['items']);
    }

    public function testSubjectsAreIsolatedFromEachOtherAndFromTheInstallation(): void
    {
        $this->svc->setFrameworks(1, ['soc2']);
        $this->svc->setFrameworks(2, ['soc2']);
        $this->svc->record(1, 'access_review', 1, 'A', '2025-12-01', null, null);
        self::assertArrayHasKey('access_review', $this->svc->attestations(1)->latestPerItem());
        self::assertArrayNotHasKey('access_review', $this->svc->attestations(2)->latestPerItem());
        self::assertSame([], (new AttestationStore($this->db))->latestPerItem(), 'the installation sees none of it');
        $id = $this->svc->snapshot(1, 1);
        self::assertNotNull($this->svc->snapshots(1)->get($id));
        self::assertNull($this->svc->snapshots(2)->get($id), 'another subject cannot read the snapshot');
        self::assertNull((new SnapshotStore($this->db))->get($id), 'nor the installation');
        try {
            $this->svc->share(2, $id, null, 1);
            self::fail('shared another subject\'s snapshot');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        try {
            $this->svc->record(1, 'security_policy_review', 1, 'A', '2025-12-01', null, null); // an installation-only id
            self::fail('accepted an item that is not on the customer checklist');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testShareUnshareAndReducedView(): void
    {
        $this->svc->setFrameworks(3, ['iso27001']);
        $this->svc->record(3, 'access_review', 9, 'Hidden Tech', '2025-12-01', null, 'PRIVATE');
        $id = $this->svc->snapshot(3, 9);
        self::assertNull($this->svc->shared(3));
        $this->svc->share(3, $id, 'Hi', 9);
        $s = $this->svc->shared(3);
        self::assertSame('Hi', $s['note']);
        $json = json_encode($s);
        foreach (['Hidden Tech', 'PRIVATE'] as $leak) {
            self::assertStringNotContainsString($leak, $json);
        }
        self::assertNull($this->svc->shared(4), 'another subject sees nothing');
        $overview = $this->svc->overview();
        self::assertCount(1, $overview);
        self::assertTrue($overview[0]['shared']);
        self::assertSame(3, $overview[0]['subject_id']);
        $this->svc->unshare(3);
        self::assertNull($this->svc->shared(3));
        self::assertSame($id, $this->svc->snapshots(3)->list()[0]['snapshot_id'], 'unsharing keeps the snapshot');
    }
}
