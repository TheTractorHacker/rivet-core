<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/** Per-OS check targeting (rc.24): different lists per device OS, signed per entry, stale row and alert cleanup, no flapping. */
final class CheckOsTargetingTest extends RmmTestCase
{
    private int $win = 0;
    private int $lin = 0;
    private string $winT = '';
    private string $linT = '';

    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness(new MutableClock(), null, ['allow_linux' => true]);
    }

    /** @param list<array<string,mixed>> $checks */
    private function define(array $checks): void
    {
        $this->assertSame([], $this->h->module->settings()->update(['checks_json' => json_encode($checks)]));
    }

    private function devices(): void
    {
        foreach (['windows' => 'W1', 'linux' => 'L1'] as $os => $serial) {
            $this->h->asset(['name' => "Box $serial", 'serial' => $serial]);
            [, , $j] = $this->h->enroll($this->h->token(null, 24, 50), $this->h::device(['serial' => $serial, 'hostname' => "H-$serial", 'os' => $os]));
            if ($os === 'windows') {
                [$this->win, $this->winT] = [(int) $j['device_id'], (string) $j['device_token']];
            } else {
                [$this->lin, $this->linT] = [(int) $j['device_id'], (string) $j['device_token']];
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private static function schedule(): array
    {
        return [
            ['key' => 'svc_eventlog', 'type' => 'service', 'params' => ['name' => 'EventLog'], 'interval_s' => 300, 'os' => 'windows'],
            ['key' => 'svc_cron', 'type' => 'service', 'params' => ['name' => 'cron'], 'interval_s' => 300, 'os' => 'linux'],
            ['key' => 'svc_any', 'type' => 'service', 'params' => ['name' => 'any'], 'interval_s' => 300, 'os' => 'any'],
            ['key' => 'pending_reboot', 'type' => 'pending_reboot', 'params' => [], 'interval_s' => 3600],
        ];
    }

    /** @return list<string> */
    private function delivered(string $token): array
    {
        [$c, , $r] = $this->h->checkin($token);
        $this->assertSame(200, $c);

        return array_column($r['config']['checks'], 'key');
    }

    public function testAWindowsAndALinuxDeviceGetDifferentSignedLists(): void
    {
        $this->devices();
        $this->define(self::schedule());
        [, , $w] = $this->h->checkin($this->winT);
        [, , $l] = $this->h->checkin($this->linT);
        $this->assertSame(['svc_eventlog', 'svc_any', 'pending_reboot'], array_column($w['config']['checks'], 'key'));
        $this->assertSame(['svc_cron', 'svc_any', 'pending_reboot'], array_column($l['config']['checks'], 'key'));
        foreach ([$w, $l] as $r) {
            foreach ($r['config']['checks'] as $c) {
                $sig = $c['signature'];
                $this->assertSame($c['key'] === 'svc_any' || $c['key'] === 'pending_reboot' ? false : true, isset($c['os']), 'os is delivered (and signed) only when set');
                $this->assertTrue(Signer::verify(Signer::checkMessage($c), $sig, $this->publicKey()), 'signature covers the delivered entry ' . $c['key']);
                $tampered = $c;
                $tampered['os'] = $c['os'] ?? 'linux';
                if (!isset($c['os'])) {
                    $this->assertFalse(Signer::verify(Signer::checkMessage($tampered), $sig, $this->publicKey()), 'adding an os breaks the signature');
                }
            }
        }
    }

    private function publicKey(): string
    {
        return (string) $this->h->module->settings()->signingKey()[1];
    }

    public function testStaleRowsAndTheirAlertsAreCleanedOnceTheCheckNoLongerApplies(): void
    {
        $this->devices();
        $this->h->module->settings()->set(['failure_debounce' => 1]);
        // The old, OS-blind schedule: everything goes to everybody.
        $this->define([
            ['key' => 'svc_cron', 'type' => 'service', 'params' => ['name' => 'cron'], 'interval_s' => 300],
            ['key' => 'svc_any', 'type' => 'service', 'params' => ['name' => 'any'], 'interval_s' => 300],
        ]);
        $this->h->checkin($this->winT, ['checks' => [
            ['key' => 'svc_cron', 'status' => 'fail', 'detail' => 'service cron not found', 'at' => $this->h::ts()],
            ['key' => 'svc_any', 'status' => 'fail', 'detail' => 'down', 'at' => $this->h::ts()],
        ]]);
        $this->h->checkin($this->linT, ['checks' => [['key' => 'svc_cron', 'status' => 'fail', 'detail' => 'down', 'at' => $this->h::ts()]]]);
        $intg = $this->h->integrationId();
        $alert = fn (int $dev, string $k): ?int => ($v = $this->h->one("SELECT alert_id FROM endpoint_agent_checks WHERE device_id=$dev AND check_key='$k'")) === null ? null : (int) $v;
        $wCron = $alert($this->win, 'svc_cron');
        $wAny = $alert($this->win, 'svc_any');
        $lCron = $alert($this->lin, 'svc_cron');
        $this->assertNotNull($wCron);
        $this->assertSame('new', $this->h->bridge->alert($wCron)['status']);
        $created = count($this->h->bridge->alertKeys());

        // The edition now says svc_cron is Linux only.
        $this->define([
            ['key' => 'svc_cron', 'type' => 'service', 'params' => ['name' => 'cron'], 'interval_s' => 300, 'os' => 'linux'],
            ['key' => 'svc_any', 'type' => 'service', 'params' => ['name' => 'any'], 'interval_s' => 300],
        ]);
        $this->assertSame(['svc_any'], $this->delivered($this->winT));

        $this->assertNull($this->h->one("SELECT 1 FROM endpoint_agent_checks WHERE device_id={$this->win} AND check_key='svc_cron'"), 'the stale row is gone');
        $this->assertSame('resolved', $this->h->bridge->alert($wCron)['status'], 'its alert is auto-resolved');
        $this->assertSame($wAny, $alert($this->win, 'svc_any'), 'a check that is still delivered keeps its row and alert');
        $this->assertNotSame('resolved', $this->h->bridge->alert($wAny)['status'], 'no flapping: the live alert is untouched');
        $this->assertSame($lCron, $alert($this->lin, 'svc_cron'));
        $this->assertSame(['svc_cron', 'svc_any'], $this->delivered($this->linT));
        $this->assertNotSame('resolved', $this->h->bridge->alert($lCron)['status']);
        $this->assertSame($created, count($this->h->bridge->alertKeys()), 'no new alert or ticket was raised by the cleanup');

        // A later check-in of the same set does nothing more (and is idempotent).
        $this->assertSame(['svc_any'], $this->delivered($this->winT));
        $this->assertSame($wAny, $alert($this->win, 'svc_any'));
        $this->assertSame($intg, $this->h->integrationId());
    }

    public function testServerReportedKeysAreNeverCleaned(): void
    {
        $this->devices();
        $this->define(self::schedule());
        $this->h->q("INSERT INTO endpoint_agent_checks (device_id, check_key, status, detail) VALUES ({$this->win}, 'patch.compliance', 'fail', 'x'), ({$this->win}, 'log.rule_7', 'fail', 'x'), ({$this->win}, 'change.rule_2', 'fail', 'x'), ({$this->win}, 'gone_key', 'fail', 'x')");
        $this->delivered($this->winT);
        $this->assertSame(['change.rule_2', 'log.rule_7', 'patch.compliance'], array_column($this->rows($this->win), 'check_key'));
    }

    /** @return list<array<string,mixed>> */
    private function rows(int $dev): array
    {
        $out = [];
        $r = $this->h->mysqli->query("SELECT check_key FROM endpoint_agent_checks WHERE device_id=$dev ORDER BY check_key");
        while ($row = $r->fetch_assoc()) {
            $out[] = $row;
        }

        return $out;
    }

    public function testTheBackfillCleansDevicesThatDoNotCheckIn(): void
    {
        $this->devices();
        $this->h->module->settings()->set(['failure_debounce' => 1]);
        $this->define([['key' => 'svc_cron', 'type' => 'service', 'params' => ['name' => 'cron'], 'interval_s' => 300]]);
        $this->h->checkin($this->winT, ['checks' => [['key' => 'svc_cron', 'status' => 'fail', 'detail' => 'not found', 'at' => $this->h::ts()]]]);
        $id = (int) $this->h->one("SELECT alert_id FROM endpoint_agent_checks WHERE device_id={$this->win}");
        $this->define([['key' => 'svc_cron', 'type' => 'service', 'params' => ['name' => 'cron'], 'interval_s' => 300, 'os' => 'linux']]);
        $r = $this->h->module->reconcileChecks();
        $this->assertSame(1, $r['removed']);
        $this->assertSame('resolved', $this->h->bridge->alert($id)['status']);
        $this->assertSame(0, $this->h->module->reconcileChecks()['removed'], 'idempotent');
        $this->assertSame(['devices' => 1, 'removed' => 0], $this->h->module->reconcileChecks($this->lin));
    }

    public function testASettingsSaveWithABadOsIsRejected(): void
    {
        $this->devices();
        $errs = $this->h->module->settings()->update(['checks_json' => json_encode([['key' => 'a', 'type' => 'service', 'params' => ['name' => 'x'], 'interval_s' => 60, 'os' => 'beos']])]);
        $this->assertNotSame([], $errs);
        $this->assertStringContainsString('os must be one of windows, linux or any', json_encode($errs));
    }

    public function testWithAlertingOnTheAlertRecordIsResolvedBySystemWithTheReason(): void
    {
        $this->devices();
        $this->assertSame([], $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'alerting' => true], 'failure_debounce' => 1]));
        $this->h->module->alertingEngine()->forget();
        $this->define([['key' => 'svc_cron', 'type' => 'service', 'params' => ['name' => 'cron'], 'interval_s' => 300]]);
        $this->h->checkin($this->winT, ['checks' => [['key' => 'svc_cron', 'status' => 'fail', 'detail' => 'not found', 'at' => $this->h::ts()]]]);
        $this->assertSame('open', $this->h->one("SELECT IF(state='resolved','resolved','open') FROM rmm_alert_meta WHERE device_id={$this->win}"));
        $this->define([['key' => 'svc_cron', 'type' => 'service', 'params' => ['name' => 'cron'], 'interval_s' => 300, 'os' => 'linux']]);
        $this->delivered($this->winT);
        $m = $this->h->mysqli->query("SELECT state, resolved_by, resolve_reason FROM rmm_alert_meta WHERE device_id={$this->win}")->fetch_assoc();
        $this->assertSame(['state' => 'resolved', 'resolved_by' => null, 'resolve_reason' => 'not_applicable'], $m);
        $this->assertNull($this->h->one("SELECT 1 FROM rmm_check_eval WHERE device_id={$this->win} AND check_key='svc_cron'"));
    }
}
