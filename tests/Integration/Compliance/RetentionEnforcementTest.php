<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Compliance;

use PHPUnit\Framework\Attributes\DataProvider;
use RivetCore\Compliance\Profile\FrameworkProfileStore;
use RivetCore\Compliance\Profile\Requirement;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Compliance\Profile\RetentionFloors;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Rmm\Settings\RetentionClasses;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * The selected framework's retention minimums are enforced in the three RMM settings that own a retention: refused below the minimum (naming the framework
 * and the requirement), accepted at and above it, applied on read so housekeeping can never delete younger rows, and invisible without a selected profile.
 */
final class RetentionEnforcementTest extends RmmTestCase
{
    private FrameworkProfileStore $profiles;
    private RetentionFloors $floors;

    protected function makeHarness(): RmmHarness
    {
        $this->floors = RetentionFloors::fromLoader(fn (): array => $this->profiles->retentionFloors(), fn (): string => $this->profiles->selectedProfile()?->displayName() ?? 'none');

        return new RmmHarness(null, null, ['retention_floors' => $this->floors]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new MigrationRunner($this->h->db, CoreMigrations::all(), $this->h->clock))->run();
        foreach (['compliance_profiles', 'compliance_profile_versions', 'compliance_profile_requirements', 'compliance_profile_selection', 'compliance_profile_selection_log'] as $t) {
            $this->h->db->execute("DELETE FROM $t");
        }
        $this->profiles = new FrameworkProfileStore($this->h->db, $this->h->clock);
    }

    /** @return array<string, array{string, string, string, int, int}> class, settings source, limit key, default, floor to ask for (inside the setting's range) */
    public static function classes(): array
    {
        return [
            'rmm_logs' => ['rmm_logs', 'remote', 'logs_retention_days', 7, 30],
            'change_records' => ['change_records', 'change', 'change_retention_days', 90, 200],
            'check_history' => ['check_history', 'rmm', 'check_history_days', 7, 100],
            'software_history' => ['software_history', 'rmm', 'software_history_days', 365, 400],
            'patch_history' => ['patch_history', 'rmm', 'patch_history_days', 365, 400],
            'deployment_history' => ['deployment_history', 'rmm', 'software_deploy_history_days', 365, 400],
            'metrics_hourly' => ['metrics_hourly', 'rmm', 'metric_hourly_days', 14, 30],
            'metrics_daily' => ['metrics_daily', 'rmm', 'metric_daily_days', 400, 500],
        ];
    }

    private function select(string $class, int $days): void
    {
        $this->profiles->create('contract_x', 'Contract X', '', [new Requirement(RequirementType::RETENTION_MIN, $class, $days)], 1, 'Alex');
        $this->profiles->select('contract_x', 1, 'Alex');
        $this->floors->refresh();
    }

    /** @return list<string> errors */
    private function save(string $source, string $key, int $days): array
    {
        return match ($source) {
            'rmm' => $this->h->module->settings()->update(['limits_json' => [$key => $days]]),
            'remote' => $this->h->module->remoteSettings()->update(['limits' => [$key => $days]], 1),
            'change' => $this->h->module->changeSettings()->update(['limits' => [$key => $days]], 1),
        };
    }

    /** @return array<string,int> */
    private function limits(string $source): array
    {
        return match ($source) {
            'rmm' => $this->h->module->settings()->limits(),
            'remote' => $this->h->module->remoteSettings()->limits(),
            'change' => $this->h->module->changeSettings()->limits(),
        };
    }

    #[DataProvider('classes')]
    public function testBelowTheMinimumIsRefusedAtAndAboveIsAccepted(string $class, string $source, string $key, int $default, int $floor): void
    {
        $this->select($class, $floor);
        self::assertSame($floor, $this->limits($source)[$key], 'the effective value is raised to the minimum at once');

        $err = $this->save($source, $key, $floor - 1);
        self::assertCount(1, $err, $class);
        self::assertStringContainsString('cannot be set below ' . $floor . ' days', $err[0]);
        self::assertStringContainsString('Contract X (version 1)', $err[0], 'names the framework');
        self::assertStringContainsString('minimum retention of ' . RetentionClasses::LABELS[$class], $err[0], 'names the requirement');
        self::assertStringContainsString('Use ' . $floor . ' days or more', $err[0]);

        self::assertSame([], $this->save($source, $key, $floor), 'equal to the minimum');
        self::assertSame([], $this->save($source, $key, $floor + 5), 'above the minimum');
        $this->profiles->select(null, 1, 'Alex');
        $this->floors->refresh();
        self::assertSame($floor + 5, $this->limits($source)[$key], 'the value above the minimum is what was stored');
    }

    #[DataProvider('classes')]
    public function testARefusedSaveWritesNothing(string $class, string $source, string $key, int $default, int $floor): void
    {
        $this->save($source, $key, $floor + 1);   // no profile yet: stored as given
        $this->select($class, $floor + 2);
        self::assertNotSame([], $this->save($source, $key, $floor));
        $this->profiles->select(null, 1, 'Alex');
        $this->floors->refresh();
        self::assertSame($floor + 1, $this->limits($source)[$key], 'the stored value is untouched');
    }

    #[DataProvider('classes')]
    public function testWithoutASelectedProfileEverythingBehavesAsBefore(string $class, string $source, string $key, int $default, int $floor): void
    {
        self::assertSame($default, $this->limits($source)[$key]);
        $bounds = RetentionClasses::bounds($class);
        self::assertNotNull($bounds);
        self::assertSame([], $this->save($source, $key, $bounds[0] + 0), 'the lowest value the setting allows is accepted');
        self::assertSame($bounds[0], $this->limits($source)[$key]);
        // a built-in profile asks only for audit retention: no RMM setting changes
        $this->profiles->select('hipaa', 1, 'Alex');
        $this->floors->refresh();
        self::assertSame($bounds[0], $this->limits($source)[$key]);
        self::assertSame([], $this->save($source, $key, $bounds[0]));
    }

    public function testAStoredValueBelowANewMinimumIsRaisedOnReadAndTheStoredRowKeepsItsValue(): void
    {
        self::assertSame([], $this->h->module->settings()->update(['limits_json' => ['check_history_days' => 14, 'metric_daily_days' => 200]]));
        self::assertSame(14, $this->h->module->settings()->explicitLimits()['check_history_days']);
        $this->select('check_history', 90);
        self::assertSame(90, $this->h->module->settings()->limits()['check_history_days']);
        self::assertSame(90, $this->h->module->settings()->explicitLimits()['check_history_days'], 'explicit limits feed the housekeeping too');
        self::assertSame(200, $this->h->module->settings()->limits()['metric_daily_days'], 'a class without a minimum is untouched');
        $row = json_decode((string) $this->h->one('SELECT limits_json FROM endpoint_agent_settings WHERE id = 1'), true);
        self::assertSame(14, $row['check_history_days'], 'the database row still holds what the administrator saved');
    }

    public function testHousekeepingNeverPrunesYoungerThanTheMinimum(): void
    {
        $this->h->enable();
        $dev = $this->enrolled();
        $this->select('check_history', 100);
        $young = gmdate('Y-m-d H:i:s', time() - 40 * 86400);   // older than the stored 7 days, younger than the floor
        $old = gmdate('Y-m-d H:i:s', time() - 150 * 86400);
        $this->h->q("INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES ($dev, 'disk', 'ok', 'young', '$young'), ($dev, 'disk', 'ok', 'old', '$old')");
        $this->h->module->housekeeping()->run();
        self::assertSame(['young'], array_column($this->h->rows('SELECT detail FROM endpoint_agent_check_history ORDER BY hist_id'), 'detail'));
    }

    public function testWithoutTheFloorsTheSameRowIsPrunedAsBefore(): void
    {
        $h = new RmmHarness();
        $h->enable();
        self::assertNull($h->module->settings()->retentionFloors());
        [, , $j] = $h->enroll($h->token(null, 24, 5), $h::device(['serial' => 'NOFLOOR-1']));
        $dev = (int) $j['device_id'];
        $young = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $h->q("INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES ($dev, 'disk', 'ok', 'young', '$young')");
        $h->module->housekeeping()->run();
        self::assertSame(0, (int) $h->one('SELECT COUNT(*) FROM endpoint_agent_check_history'), 'the default 7-day history is pruned: no framework, no change');
    }

    public function testAMinimumAboveTheSettingsOwnMaximumAppliesByItselfAndDoesNotBlockTheForm(): void
    {
        $max = RetentionClasses::bounds('metrics_hourly')[1];
        self::assertSame(400, $max);
        $this->select('metrics_hourly', 1000);
        self::assertSame([], $this->save('rmm', 'metric_hourly_days', $max), 'no value of the setting could satisfy 1000 days, so none is refused');
        self::assertSame(1000, $this->h->module->settings()->limits()['metric_hourly_days'], 'the minimum is applied over the setting');
    }

    public function testOtherLimitsInTheSameSaveAreNotBlockedButAnyViolationRefusesTheWholeSave(): void
    {
        $this->select('check_history', 100);
        self::assertSame([], $this->h->module->settings()->update(['limits_json' => ['approval_expiry_h' => 60, 'check_history_days' => 120]]));
        self::assertSame(60, $this->h->module->settings()->limits()['approval_expiry_h']);
        $err = $this->h->module->settings()->update(['limits_json' => ['approval_expiry_h' => 70, 'check_history_days' => 99]]);
        self::assertNotSame([], $err);
        self::assertSame(60, $this->h->module->settings()->limits()['approval_expiry_h'], 'nothing of a refused save is written');
        self::assertSame(120, $this->h->module->settings()->explicitLimits()['check_history_days']);
    }

    public function testZeroMeaningRecordNoneIsBelowAnyMinimum(): void
    {
        $this->select('check_history', 30);
        $err = $this->h->module->settings()->update(['limits_json' => ['check_history_days' => 0]]);
        self::assertCount(1, $err);
        self::assertStringContainsString('cannot be set below 30 days', $err[0]);
    }

    public function testEditingTheSelectedProfileChangesTheEnforcementFromThatMoment(): void
    {
        $this->select('check_history', 30);
        self::assertNotSame([], $this->save('rmm', 'check_history_days', 20));
        $this->profiles->update('contract_x', 'Contract X', '', [new Requirement(RequirementType::RETENTION_MIN, 'check_history', 10)], 1, 'Alex');
        $this->floors->refresh();
        self::assertSame([], $this->save('rmm', 'check_history_days', 20));
        $err = $this->save('rmm', 'check_history_days', 5);
        self::assertStringContainsString('Contract X (version 2)', $err[0]);
    }

    public function testAnUnreadableProfileStoreMeansNoFloorNotAnOutage(): void
    {
        $log = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');
        try {
            $f = RetentionFloors::fromLoader(static function (): array {
                throw new \RuntimeException('Table compliance_profile_selection does not exist');
            }, static fn (): string => 'x');
            self::assertSame(0, $f->floorDays('rmm_logs'));
            self::assertSame(7, $f->effectiveDays('rmm_logs', 7));
            self::assertSame([], $f->all());
            $this->h->module->settings()->setRetentionFloors($f);
            self::assertSame(7, $this->h->module->settings()->limits()['check_history_days']);
        } finally {
            ini_set('error_log', $log);
        }
    }

    public function testEffectiveDaysAndTheMessageHelpers(): void
    {
        $this->select('rmm_logs', 30);
        self::assertSame(30, $this->floors->floorDays('rmm_logs'));
        self::assertSame(30, $this->floors->effectiveDays('rmm_logs', 7));
        self::assertSame(90, $this->floors->effectiveDays('rmm_logs', 90));
        self::assertSame(7, $this->floors->effectiveDays('check_history', 7), 'no minimum for another class');
        self::assertSame('Contract X (version 1)', $this->floors->requiredBy());
        self::assertSame([], RetentionClasses::violations(['logs_retention_days' => 5], 'remote', null));
        self::assertSame([], RetentionClasses::violations(['logs_retention_days' => 'abc', 'unknown' => 1], 'remote', $this->floors), 'only whole numbers are judged; the setting rejects the rest');
        self::assertSame(['logs_retention_days' => 30], RetentionClasses::raise(['logs_retention_days' => 7], 'remote', $this->floors));
        self::assertSame(['x' => 1], RetentionClasses::raise(['x' => 1], 'remote', $this->floors));
    }

    private function enrolled(): int
    {
        $this->h->asset(['name' => 'R', 'serial' => 'RETAIN-1']);
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 5), $this->h::device(['serial' => 'RETAIN-1']));

        return (int) $j['device_id'];
    }
}
