<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\Backup\BackupStore;
use RivetCore\Compliance\Check\BackupCoverageCheck;
use RivetCore\Compliance\Check\BackupEncryptedCheck;
use RivetCore\Compliance\Check\BackupOffsiteCheck;
use RivetCore\Compliance\Check\BackupRecencyCheck;
use RivetCore\Compliance\Check\BackupRetentionCheck;
use RivetCore\Compliance\Check\IntegrityChainCheck;
use RivetCore\Compliance\Profile\FixedRequirementsProvider;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\Requirement;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Compliance\Status;
use RivetCore\Tests\Support\FakeBackupFacts;
use RivetCore\Tests\Support\FixedClock;

final class BackupChecksTest extends TestCase
{
    private const NOW = '2026-10-26 12:00:00';

    private static function clock(): FixedClock
    {
        return new FixedClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')));
    }

    private static function ago(string $spec): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->modify('-' . $spec);
    }

    /** @param list<Requirement> $reqs */
    private static function profile(array $reqs): FixedRequirementsProvider
    {
        return new FixedRequirementsProvider(new FrameworkProfile('contract_x', 'Contract X', '', 3, false, $reqs));
    }

    private static function good(): BackupFacts
    {
        return new BackupFacts(true, self::ago('3 hours'), [BackupStore::CONTROL_DB, BackupStore::RMM_POSTGRES], ['local', 'offsite'], true, 2600, self::ago('2 hours'), 'age', null, [], 'deploy/backup.sh');
    }

    public function testEveryCheckIsNotApplicableWithoutAProfileOrWithoutItsRequirement(): void
    {
        $none = new FixedRequirementsProvider(null);
        $facts = new FakeBackupFacts(self::good());
        $unrelated = self::profile([new Requirement(RequirementType::RETENTION_MIN, 'audit_events', 365)]);
        foreach ([$none, $unrelated] as $p) {
            foreach ([
                new BackupCoverageCheck($p, $facts, self::clock(), fn () => 'postgres'), new BackupRecencyCheck($p, $facts, self::clock()), new BackupEncryptedCheck($p, $facts, self::clock()),
                new BackupOffsiteCheck($p, $facts, self::clock()), new BackupRetentionCheck($p, $facts, self::clock()), new IntegrityChainCheck($p, self::clock()),
            ] as $check) {
                $r = $check->run();
                self::assertSame(Status::NotApplicable, $r->status, $check->id());
                self::assertNull($r->status->weight(), 'left out of the score');
            }
        }
    }

    public function testCoverageIsRedWhenThePostgresStoreIsMissingAndGreenWhenPresent(): void
    {
        $p = self::profile([new Requirement(RequirementType::BACKUP_COVERS_ALL_STORES, null, 1)]);
        $facts = new FakeBackupFacts(new BackupFacts(true, self::ago('1 hours'), [BackupStore::CONTROL_DB], ['local'], true, 30));
        $check = new BackupCoverageCheck($p, $facts, self::clock(), fn () => 'postgres', '/admin/compliance');
        $r = $check->run();
        self::assertSame(Status::Fail, $r->status);
        self::assertStringContainsString('PostgreSQL RMM store', $r->summary);
        self::assertStringContainsString('Not met: PostgreSQL RMM store is not in the backup.', (string) $r->detail);
        self::assertStringContainsString('Met: Control database is in the backup.', (string) $r->detail);
        self::assertSame('/admin/compliance', $r->fixPath);

        $facts->facts = self::good();
        self::assertSame(Status::Pass, $check->run()->status);

        // on MariaDB the control database alone is complete
        $facts->facts = new BackupFacts(true, self::ago('1 hours'), [BackupStore::CONTROL_DB], ['local']);
        self::assertSame(Status::Pass, (new BackupCoverageCheck($p, $facts, self::clock(), fn () => 'mariadb'))->run()->status);
        // the store kind is read when the check runs, not when it is built
        $kind = 'mariadb';
        $live = new BackupCoverageCheck($p, $facts, self::clock(), function () use (&$kind): string { return $kind; });
        self::assertSame(Status::Pass, $live->run()->status);
        $kind = 'postgres';
        self::assertSame(Status::Fail, $live->run()->status);
    }

    public function testNoSuccessfulBackupFailsEveryBackupCheckAndUnavailableFactsWarnHonestly(): void
    {
        $p = self::profile([
            new Requirement(RequirementType::BACKUP_COVERS_ALL_STORES, null, 1), new Requirement(RequirementType::BACKUP_RPO_MAX, null, 24), new Requirement(RequirementType::BACKUP_ENCRYPTED, null, 1),
            new Requirement(RequirementType::BACKUP_OFFSITE, null, 1), new Requirement(RequirementType::RETENTION_MIN, 'backups', 100),
        ]);
        $mk = fn (FakeBackupFacts $f): array => [
            new BackupCoverageCheck($p, $f, self::clock(), fn () => 'mariadb'), new BackupRecencyCheck($p, $f, self::clock()), new BackupEncryptedCheck($p, $f, self::clock()),
            new BackupOffsiteCheck($p, $f, self::clock()),
        ];
        foreach ($mk(new FakeBackupFacts(new BackupFacts(true))) as $c) {
            $r = $c->run();
            self::assertSame(Status::Fail, $r->status, $c->id());
            self::assertSame('No successful backup is on record.', $r->summary);
        }
        foreach ($mk(new FakeBackupFacts(BackupFacts::unavailable())) as $c) {
            $r = $c->run();
            self::assertSame(Status::Warn, $r->status, $c->id() . ': unknown is not a pass and not a proven failure');
            self::assertStringStartsWith('Not available:', $r->summary);
        }
        $throwing = new class implements \RivetCore\Compliance\Backup\BackupFactsProviderInterface {
            public function backupFacts(): BackupFacts
            {
                throw new \RuntimeException('secret path /var/backups/x');
            }
        };
        $r = (new BackupEncryptedCheck($p, $throwing, self::clock()))->run();
        self::assertSame(Status::Warn, $r->status);
        self::assertStringNotContainsString('/var/backups', $r->summary . $r->detail);
    }

    /** @return array<string, array{string, Status}> */
    public static function ages(): array
    {
        return [
            'fresh' => ['3 hours', Status::Pass],
            'exactly the limit' => ['24 hours', Status::Pass],
            'just late' => ['25 hours', Status::Warn],
            'late by the grace' => ['26 hours 24 minutes', Status::Warn],
            'too old' => ['27 hours', Status::Fail],
            'a week' => ['7 days', Status::Fail],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ages')]
    public function testRecencyAgainstTheRpo(string $age, Status $expect): void
    {
        $p = self::profile([new Requirement(RequirementType::BACKUP_RPO_MAX, null, 24)]);
        $f = new FakeBackupFacts(new BackupFacts(true, self::ago($age), [BackupStore::CONTROL_DB], ['local']));
        $r = (new BackupRecencyCheck($p, $f, self::clock()))->run();
        self::assertSame($expect, $r->status, $r->summary);
        self::assertSame(24, $r->metrics['max_hours']);
    }

    public function testEncryptionTriState(): void
    {
        $p = self::profile([new Requirement(RequirementType::BACKUP_ENCRYPTED, null, 1)]);
        $mk = fn (?bool $enc) => (new BackupEncryptedCheck($p, new FakeBackupFacts(new BackupFacts(true, self::ago('1 hours'), [BackupStore::CONTROL_DB], ['local'], $enc, null, null, 'age')), self::clock()))->run();
        self::assertSame(Status::Pass, $mk(true)->status);
        self::assertStringContainsString('age', $mk(true)->summary);
        self::assertSame(Status::Fail, $mk(false)->status);
        self::assertSame(Status::Warn, $mk(null)->status);
    }

    public function testOffsiteNeedsADestinationAnArrivalAndAFreshCopy(): void
    {
        $p = self::profile([new Requirement(RequirementType::BACKUP_OFFSITE, null, 1), new Requirement(RequirementType::BACKUP_RPO_MAX, null, 24)]);
        $run = fn (BackupFacts $f) => (new BackupOffsiteCheck($p, new FakeBackupFacts($f), self::clock()))->run();
        self::assertSame(Status::Fail, $run(new BackupFacts(true, self::ago('1 hours'), [BackupStore::CONTROL_DB], ['local']))->status, 'local only');
        self::assertSame(Status::Fail, $run(new BackupFacts(true, self::ago('1 hours'), [BackupStore::CONTROL_DB], ['local', 'offsite']))->status, 'offsite listed but never arrived');
        self::assertSame(Status::Pass, $run(new BackupFacts(true, self::ago('1 hours'), [BackupStore::CONTROL_DB], ['local', 'offsite'], null, null, self::ago('2 hours')))->status);
        $stale = $run(new BackupFacts(true, self::ago('1 hours'), [BackupStore::CONTROL_DB], ['local', 'offsite'], null, null, self::ago('9 days')));
        self::assertSame(Status::Fail, $stale->status, 'a fresh local backup does not make a week-old upload current');
        self::assertStringContainsString('9 days', $stale->summary);
        // without an RPO in the profile only the existence of the copy is required
        $p2 = self::profile([new Requirement(RequirementType::BACKUP_OFFSITE, null, 1)]);
        self::assertSame(Status::Pass, (new BackupOffsiteCheck($p2, new FakeBackupFacts(new BackupFacts(true, self::ago('1 hours'), [BackupStore::CONTROL_DB], ['offsite'], null, null, self::ago('9 days'))), self::clock()))->run()->status);
    }

    public function testBackupRetention(): void
    {
        $p = self::profile([new Requirement(RequirementType::RETENTION_MIN, 'backups', 2555)]);
        $run = fn (?int $days) => (new BackupRetentionCheck($p, new FakeBackupFacts(new BackupFacts(true, self::ago('1 hours'), [BackupStore::CONTROL_DB], ['local'], null, $days)), self::clock()))->run();
        self::assertSame(Status::Pass, $run(2555)->status);
        self::assertSame(Status::Pass, $run(4000)->status);
        self::assertSame(Status::Pass, $run(0)->status, '0 = never deleted');
        self::assertSame(Status::Fail, $run(2554)->status);
        self::assertSame(Status::Fail, $run(30)->status);
        self::assertSame(Status::Warn, $run(null)->status);
        // a retention requirement for another class is not this check's business
        $other = self::profile([new Requirement(RequirementType::RETENTION_MIN, 'rmm_logs', 30)]);
        self::assertSame(Status::NotApplicable, (new BackupRetentionCheck($other, new FakeBackupFacts(self::good()), self::clock()))->run()->status);
    }

    public function testTamperEvidenceIsReportedAsNotAvailableNeverAsAPass(): void
    {
        $p = self::profile([new Requirement(RequirementType::TAMPER_EVIDENCE, 'audit_events', 1), new Requirement(RequirementType::TAMPER_EVIDENCE, 'rmm_logs', 1)]);
        $r = (new IntegrityChainCheck($p, self::clock()))->run();
        self::assertSame(Status::Fail, $r->status);
        self::assertStringStartsWith('Not available:', $r->summary);
        self::assertStringContainsString('Audit events, RMM logs', (string) $r->detail);
        self::assertFalse($r->metrics['implemented']);
    }

    public function testChecksCarryIndicativeControlsForBuiltInFrameworksOnly(): void
    {
        $p = self::profile([]);
        $f = new FakeBackupFacts(self::good());
        foreach ([new BackupCoverageCheck($p, $f, self::clock(), fn () => 'mariadb'), new BackupRecencyCheck($p, $f, self::clock()), new BackupEncryptedCheck($p, $f, self::clock()), new BackupOffsiteCheck($p, $f, self::clock()), new BackupRetentionCheck($p, $f, self::clock()), new IntegrityChainCheck($p, self::clock())] as $c) {
            self::assertMatchesRegularExpression('/^[a-z_]+\z/', $c->id());
            self::assertNotSame('', $c->title());
            self::assertNotSame('', $c->why());
            self::assertNotSame([], $c->controls(), $c->id());
            foreach (array_keys($c->controls()) as $fw) {
                self::assertTrue(\RivetCore\Compliance\Framework::isValid($fw), $c->id() . ' tags unknown framework ' . $fw);
            }
        }
    }
}
