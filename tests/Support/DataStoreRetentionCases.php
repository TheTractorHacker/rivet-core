<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Compliance\Check\DataStoreRetentionCheck;
use RivetCore\Compliance\Profile\FixedRequirementsProvider;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\Requirement;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Compliance\Profile\RetentionFloors;
use RivetCore\Compliance\Status;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Store\RmmDataStores;

/**
 * The cases of {@see DataStoreRetentionCheck} that must hold on every RMM data store. A concrete class chooses the store (MariaDB, PostgreSQL) in
 * {@see self::activateStore()}; everything else goes through the store interfaces, so the same assertions prove the check reads the ACTIVE store.
 */
abstract class DataStoreRetentionCases extends RmmTestCase
{
    protected int $dev = 0;

    /** Make the harness's module use the store under test (and clear it). */
    abstract protected function activateStore(): void;

    abstract protected function expectedKind(): string;

    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness(null, null, [], false, new \RivetCore\Rmm\Support\DatabaseMetricSink(new MysqliDatabase(ScratchDb::connect()), new MutableClock()));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->enable();
        $this->h->asset(['name' => 'R', 'serial' => 'DSR-1']);
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 5), $this->h::device(['serial' => 'DSR-1']));
        $this->dev = (int) $j['device_id'];
        $this->activateStore();
    }

    /** @param list<Requirement> $reqs */
    protected function check(array $reqs, bool $enforce = false): DataStoreRetentionCheck
    {
        $source = new FixedRequirementsProvider(new FrameworkProfile('contract_x', 'Contract X', '', 1, false, $reqs));
        $m = $this->h->module;
        if ($enforce) {
            $floors = RetentionFloors::fromProvider($source);
            $m->settings()->setRetentionFloors($floors);
            $m->remoteSettings()->setRetentionFloors($floors);
            $m->changeSettings()->setRetentionFloors($floors);
        }

        return new DataStoreRetentionCheck($source, $this->h->clock, $m->dataStores(), $m->settings(), $m->remoteSettings(), $m->changeSettings(), '/admin/rmm');
    }

    private static function min(string $class, int $days): Requirement
    {
        return new Requirement(RequirementType::RETENTION_MIN, $class, $days);
    }

    public function testTheCheckReadsTheActiveStore(): void
    {
        self::assertSame($this->expectedKind(), $this->h->module->dataStores()->kind());
        $r = $this->check([self::min('rmm_logs', 7)], true)->run();
        self::assertSame($this->expectedKind(), $r->metrics['rmm_data_store']);
        self::assertStringContainsString('RMM data store is ' . ($this->expectedKind() === RmmDataStoreKind::POSTGRES ? 'PostgreSQL' : 'MariaDB'), (string) $r->detail);
    }

    public function testPassesWhenEveryRequiredClassMeetsItsMinimumAndTheMinimumsAreEnforced(): void
    {
        $r = $this->check([
            self::min('rmm_logs', 7), self::min('change_records', 90), self::min('check_history', 7), self::min('software_history', 365), self::min('patch_history', 365),
            self::min('deployment_history', 365), self::min('metrics_hourly', 14), self::min('metrics_daily', 400),
        ], true)->run();
        self::assertSame(Status::Pass, $r->status, $r->summary . "\n" . $r->detail);
        self::assertSame(8, count(array_filter(explode("\n", (string) $r->detail), static fn (string $l): bool => str_starts_with($l, 'Met: '))));
        self::assertSame(7, $r->metrics['effective_rmm_logs']);
        self::assertSame(400, $r->metrics['effective_metrics_daily']);
    }

    public function testFailsNamingEveryClassBelowItsMinimum(): void
    {
        $r = $this->check([self::min('check_history', 100), self::min('rmm_logs', 30), self::min('patch_history', 365)])->run();
        self::assertSame(Status::Fail, $r->status);
        self::assertStringContainsString('Check history 7 < 100 days', $r->summary);
        self::assertStringContainsString('RMM logs 7 < 30 days', $r->summary);
        self::assertStringNotContainsString('Patch history', $r->summary, 'a class that meets its minimum is not blamed');
        self::assertStringContainsString('Not met: Check history are kept 7 days; the framework requires 100.', (string) $r->detail);
        self::assertStringContainsString('Met: Patch history are kept 365 days (required 365).', (string) $r->detail);
        self::assertSame('/admin/rmm', $r->fixPath);
    }

    public function testEnforcementRaisesTheEffectiveRetentionSoTheSameProfilePasses(): void
    {
        $reqs = [self::min('check_history', 100), self::min('rmm_logs', 30)];
        self::assertSame(Status::Fail, $this->check($reqs)->run()->status);
        $r = $this->check($reqs, true)->run();
        self::assertSame(Status::Pass, $r->status, $r->summary . "\n" . $r->detail);
        self::assertSame(100, $r->metrics['effective_check_history']);
    }

    public function testWithoutEnforcementWiredAMetMinimumIsOnlyAWarning(): void
    {
        $r = $this->check([self::min('rmm_logs', 7)])->run();
        self::assertSame(Status::Warn, $r->status);
        self::assertStringContainsString('not enforced by the settings', $r->summary);
        self::assertStringContainsString('Met: RMM logs are kept 7 days', (string) $r->detail);
    }

    public function testAMinimumAboveTheSettingsMaximumIsMetByTheFloor(): void
    {
        $r = $this->check([self::min('rmm_logs', 2555), self::min('metrics_hourly', 1000)], true)->run();
        self::assertSame(Status::Pass, $r->status, $r->summary . "\n" . $r->detail);
        self::assertSame(2555, $r->metrics['effective_rmm_logs']);
        self::assertSame(2555, $this->h->module->remoteSettings()->limits()['logs_retention_days']);
    }

    public function testNotApplicableWhenTheProfileAsksNothingOfTheRmmHistory(): void
    {
        self::assertSame(Status::NotApplicable, $this->check([self::min('audit_events', 365), self::min('backups', 365)])->run()->status);
        self::assertSame(Status::NotApplicable, $this->check([])->run()->status);
    }

    public function testTheOldestLogEventAndTheRowCapComeFromTheActiveStore(): void
    {
        $stores = $this->h->module->dataStores();
        $caps = ['rate_per_min' => 1000000, 'device_max_rows' => 1000000, 'fleet_max_rows' => 100000000];
        $now = time();
        $entries = [];
        for ($i = 0; $i < 960; ++$i) {
            $ts = $now - 10 * 86400 + $i;
            $entries[] = ['ts' => $ts, 'level' => 4, 'source' => 'Service', 'code' => null, 'message' => "m$i", 'hash' => substr(hash('sha256', "m$i"), 0, 32)];
        }
        $stores->logs()->admit($this->dev, $entries, $caps, $now, 0, 0);
        self::assertSame([], $this->h->module->remoteSettings()->update(['limits' => ['logs_fleet_max_rows' => 1000]], 1));

        $r = $this->check([self::min('rmm_logs', 7)], true)->run();
        self::assertSame(Status::Warn, $r->status, 'the cap is nearly reached: the retention could be cut short');
        self::assertStringContainsString('the oldest stored log event is 10 days', (string) $r->detail);
        self::assertStringContainsString('960 of at most 1000 rows', (string) $r->detail);
        self::assertStringContainsString('log row cap (1000) is nearly reached', $r->summary);

        self::assertSame([], $this->h->module->remoteSettings()->update(['limits' => ['logs_fleet_max_rows' => 100000]], 1));
        $r = $this->check([self::min('rmm_logs', 7)], true)->run();
        self::assertSame(Status::Pass, $r->status, $r->summary);
        self::assertStringContainsString('the oldest stored log event is 10 days', (string) $r->detail);
    }

    public function testMetricRetentionIsTheMetricStoresOwnUnlessTheAdministratorSetOne(): void
    {
        self::assertSame(14, $this->h->module->dataStores()->metrics()?->retentionDays());
        self::assertSame(Status::Fail, $this->check([self::min('metrics_hourly', 30)])->run()->status);
        self::assertSame([], $this->h->module->settings()->update(['limits_json' => ['metric_hourly_days' => 30]]));
        $r = $this->check([self::min('metrics_hourly', 30)], true)->run();
        self::assertSame(Status::Pass, $r->status, $r->summary . $r->detail);
        self::assertSame(30, $r->metrics['effective_metrics_hourly']);
    }

    public function testTheCheckNeverThrowsForAStoreItCannotRead(): void
    {
        $broken = new RmmDataStores(
            $this->expectedKind(),
            $this->createStub(\RivetCore\Rmm\Store\CheckHistoryStore::class),
            $this->createConfiguredStub(\RivetCore\Rmm\Store\LogStore::class, []),
            $this->createStub(\RivetCore\Rmm\Store\ChangeEventStore::class),
            $this->createStub(\RivetCore\Rmm\Store\SoftwareHistoryStore::class),
            $this->createStub(\RivetCore\Rmm\Store\PatchHistoryStore::class),
            $this->createStub(\RivetCore\Rmm\Store\DeploymentHistoryStore::class),
            $this->createStub(\RivetCore\Rmm\Store\HypervisorHistoryStore::class),
            new class implements \RivetCore\Rmm\Store\StoreMaintenance {
                public function run(array $limits, array $explicit, int $hourlyDefault, int $dailyDefault, int $rollupBudgetS = 15): array
                {
                    throw new \RuntimeException('down');
                }

                public function tiers(array $limits, array $explicit): array
                {
                    throw new \RivetCore\Rmm\Store\HotStoreUnavailableException('the PostgreSQL server is not reachable: host=10.0.0.9 password=hunter2');
                }
            },
        );
        $m = $this->h->module;
        $source = new FixedRequirementsProvider(new FrameworkProfile('contract_x', 'Contract X', '', 1, false, [self::min('check_history', 7)]));
        $check = new DataStoreRetentionCheck($source, $this->h->clock, $broken, $m->settings(), $m->remoteSettings(), $m->changeSettings());
        $m->settings()->setRetentionFloors(RetentionFloors::fromProvider($source));
        $r = $check->run();
        self::assertSame(Status::Warn, $r->status);
        self::assertStringContainsString('partition retention', $r->summary);
        self::assertStringNotContainsString('hunter2', $r->summary . $r->detail, 'an error message of the store is never shown');
        self::assertStringNotContainsString('10.0.0.9', $r->summary . $r->detail);
    }
}
