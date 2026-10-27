<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Audit\AuditService;
use RivetCore\Rmm\Checkin\CheckinService;
use RivetCore\Rmm\Http\DeviceApi;
use RivetCore\Rmm\Installer\InstallerDownload;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Maintenance\Housekeeping;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\AuditServiceRmmAudit;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Support\NullRequestContext;
use RivetCore\Tests\Support\FakeDatabase;
use RivetCore\Tests\Support\FixedClock;

/** Database-free checks of the service layer: frozen caps, the time helpers, the audit default, slugs. */
final class ServiceUnitsTest extends TestCase
{
    public function testTheFrozenCapsOfTheServicesAreTheProtocolValues(): void
    {
        $this->assertSame([1048576, 100, 100, 32, 65536], [CheckinService::MAX_BODY_BYTES, CheckinService::MAX_CHECKS, CheckinService::MAX_BUFFERED, CheckinService::MAX_DISKS, CheckinService::MAX_INVENTORY_BYTES]);
        $this->assertSame(102400, JobService::MAX_SCRIPT_BYTES);
        $this->assertSame([16384, 1048576, 262144, 4096], [RmmProtocol::ENROLL_MAX_BODY, RmmProtocol::CHECKIN_MAX_BODY, RmmProtocol::JOBS_REPORT_MAX_BODY, RmmProtocol::INSTALLER_MAX_BODY]);
        $this->assertSame([[40, 60], [120, 60], [60, 60]], [RmmProtocol::RATE_CHECKIN, RmmProtocol::RATE_JOBS, RmmProtocol::RATE_UPDATE]);
        $this->assertSame([600, 10, 60], [RmmProtocol::ENROLL_RATE_WINDOW_S, RmmProtocol::ENROLL_RATE_MAX_FAILURES, RmmProtocol::ENROLL_RATE_MAX_ATTEMPTS]);
        $this->assertSame([600, 10, 30, 30, 20], [RmmProtocol::INSTALLER_WINDOW_S, RmmProtocol::INSTALLER_IP_MAX_FAILURES, RmmProtocol::INSTALLER_IP_MAX_ATTEMPTS, RmmProtocol::INSTALLER_TOKEN_MAX_DOWNLOADS, RmmProtocol::INSTALLER_SELECTOR_MAX_FAILURES]);
        $this->assertSame([500, 5000, 20000], [Housekeeping::OFFLINE_CHUNK, Housekeeping::PRUNE_BATCH, Housekeeping::PRUNE_PAUSE_US]);
        $this->assertSame(3600, DeviceApi::DISABLED_RETRY_AFTER_S);
        $this->assertSame([60, 3600, 30, 3600], [RmmSettings::CHECK_IN_INTERVAL_MIN_S, RmmSettings::CHECK_IN_INTERVAL_MAX_S, RmmSettings::COLLECT_INTERVAL_MIN_S, RmmSettings::COLLECT_INTERVAL_MAX_S]);
    }

    public function testTheTimeHelpersFollowTheInjectedClock(): void
    {
        $sql = new Sql(new FakeDatabase(), new FixedClock(new \DateTimeImmutable('2026-03-04 05:06:07', new \DateTimeZone('Europe/Berlin'))));
        $this->assertSame('2026-03-04 04:06:07', $sql->utcNow(), 'the UTC string whatever zone the clock reports');
        $this->assertSame('2026-03-04 05:06:07', $sql->utcAt(3600));
        $this->assertSame('2026-03-04 03:06:07', $sql->utcAt(-3600));
        $this->assertSame('2026-03-04T04:06:07Z', $sql->isoNow());
        $this->assertSame(strtotime('2026-03-04 04:06:07 UTC'), $sql->time());
        $this->assertSame('2026-03-04T04:06:07Z', Sql::iso('2026-03-04 04:06:07'));
        $this->assertNull(Sql::iso(null));
        $this->assertNull(Sql::iso(''));
        $this->assertNull(Sql::iso('not a date'));
        $this->assertSame(0, Sql::ts(null));
        $this->assertSame(1772597167, Sql::ts('2026-03-04 04:06:07'));
    }

    public function testSqlHelpersMapOntoTheStorageContract(): void
    {
        $db = new FakeDatabase();
        $db->rows = [['n' => 5, 'm' => 6]];
        $sql = new Sql($db, new FixedClock());
        $this->assertSame(5, $sql->val('SELECT n FROM t WHERE a = ?', [1]));
        $this->assertSame(['n' => 5, 'm' => 6], $sql->one('SELECT * FROM t'));
        $this->assertSame(1, $sql->run('UPDATE t SET a = ?', [2]));
        $this->assertSame(1, $sql->insert('INSERT INTO t VALUES (?)', [2]));
        $db->rows = [];
        $this->assertNull($sql->val('SELECT n FROM t'));
        $this->assertSame([], $sql->all('SELECT * FROM t'));
        $this->assertSame('x', $sql->transaction(static fn (): string => 'x'));
        $this->assertSame(['SELECT n FROM t WHERE a = ?', 'SELECT * FROM t'], array_slice(array_column($db->calls, 'sql'), 0, 2));
        $this->assertSame([1], $db->calls[0]['params']);
    }

    public function testTheDefaultAuditWritesStructuredEvents(): void
    {
        $db = new FakeDatabase();
        $audit = new AuditServiceRmmAudit(new AuditService($db, new NullRequestContext()));
        $audit->record('Enrolled', 'Device 5 enrolled', 3, 77);
        $audit->record('Settings Changed!', 'x', 0, 0);
        $this->assertCount(2, $db->calls);
        $this->assertStringContainsString('INSERT INTO audit_events', $db->calls[0]['sql']);
        $this->assertSame('endpoint_agent.enrolled', $db->calls[0]['params'][0]);
        $this->assertSame([null, 'asset', '77', 'Enrolled', 'Device 5 enrolled'], array_slice($db->calls[0]['params'], 1, 5));
        $this->assertSame('endpoint_agent.settings_changed', $db->calls[1]['params'][0]);
        $this->assertNull($db->calls[1]['params'][2], 'no entity when there is none');
    }

    public function testInstallerFileNames(): void
    {
        $this->assertSame('dept-a', InstallerDownload::slug('Dept A'));
        $this->assertSame('cafe-sons-inc', InstallerDownload::slug("Caf\u{e9} & Sons, Inc."));
        $this->assertSame('department', InstallerDownload::slug('---'));
        $this->assertSame('department', InstallerDownload::slug(''));
        $this->assertSame(40, strlen(InstallerDownload::slug(str_repeat('abcdefghij', 10))));
        $this->assertSame('a-b', InstallerDownload::slug('a/../b'));
    }
}
