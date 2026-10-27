<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\Profile\FrameworkProfileStore;
use RivetCore\Compliance\Profile\Requirement;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;

/** Scratch MariaDB with every Core migration applied and the profile tables emptied; a store on a movable clock. */
abstract class ProfileTestCase extends TestCase
{
    protected MysqliDatabase $db;
    protected MutableClock $clock;
    protected FrameworkProfileStore $store;
    /** @var list<array{string, array<string,mixed>}> */
    protected array $audited = [];

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        $this->clock = new MutableClock();
        (new MigrationRunner($this->db, CoreMigrations::all(), $this->clock))->run();
        foreach (['compliance_profiles', 'compliance_profile_versions', 'compliance_profile_requirements', 'compliance_profile_selection', 'compliance_profile_selection_log', 'compliance_restore_tests'] as $t) {
            $this->db->execute("DELETE FROM $t");
        }
        $this->audited = [];
        $this->store = new FrameworkProfileStore($this->db, $this->clock, function (string $a, array $d): void {
            $this->audited[] = [$a, $d];
        });
    }

    /** @return list<Requirement> */
    protected static function contractXRequirements(): array
    {
        return [
            new Requirement(RequirementType::RETENTION_MIN, 'audit_events', 2555),
            new Requirement(RequirementType::RETENTION_MIN, 'check_history', 2555),
            new Requirement(RequirementType::BACKUP_RPO_MAX, null, 24),
            new Requirement(RequirementType::BACKUP_COVERS_ALL_STORES, null, 1),
            new Requirement(RequirementType::BACKUP_OFFSITE, null, 1),
            new Requirement(RequirementType::BACKUP_ENCRYPTED, null, 1),
            new Requirement(RequirementType::RESTORE_TEST_MAX_AGE, null, 92),
        ];
    }
}
