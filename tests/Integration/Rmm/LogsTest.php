<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Logs\LogService;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Testing\InMemoryRmmEvents;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RemoteTestCase;
use RivetCore\Tests\Support\RmmHarness;

/** Phase 8: event-log and syslog collection. Off by default, offered only to an agent that asks, bounded ingest, retention and caps, search, client scope, rules and alerts. */
final class LogsTest extends RemoteTestCase
{
    private InMemoryRmmEvents $bus;

    protected function makeHarness(): RmmHarness
    {
        $this->bus = new InMemoryRmmEvents();
        $this->transferDir = sys_get_temp_dir() . '/rmm_xfer_' . bin2hex(random_bytes(4));

        return new RmmHarness(null, null, ['allow_linux' => true, 'transfer_dir' => $this->transferDir], false, null,
            new AllowUsersPolicy([1 => true, 10 => ['rmm.device.view', 'rmm.job.run_saved', 'rmm.remote.manage', 'rmm.alert.manage'], 11 => ['rmm.device.view', 'rmm.job.run_saved'], 12 => ['rmm.device.view'], 16 => true]), null, $this->bus);
    }

    protected function setUp(): void
    {
        parent::setUp();
        [$this->dev, $this->devToken] = $this->linked('W1');
    }

    private function d(): string
    {
        return (string) $this->dev;
    }

    /** @param array<string,mixed> $limits */
    private function enableLogs(array $limits = []): void
    {
        $this->switchOn(['logs']);
        $this->assertSame([], $this->h->module->remoteSettings()->update([
            'logs_collect' => ['windows' => [['channel' => 'System', 'levels' => ['critical', 'error', 'warning'], 'event_ids' => [7036]]], 'linux' => [['units' => ['ssh.service'], 'min_level' => 'warning', 'contains' => ['failed password']]]],
        ] + ($limits === [] ? [] : ['limits' => $limits]), 1));
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array{0:int,1:array<string,mixed>|null,2:\RivetCore\Rmm\Http\RmmResponse}
     */
    private function ship(array $events, ?string $token = null, int $dropped = 0): array
    {
        $r = $this->h->api->handle($this->h->request('POST', 'agent_logs', (string) json_encode(['events' => $events, 'dropped' => $dropped, 'config_version' => 'x']), $token ?? $this->devToken));

        return [$r->status, $r->body === null ? null : json_decode($r->body, true), $r];
    }

    /** @return array<string,mixed> */
    private static function ev(string $msg = 'The Print Spooler service entered the stopped state.', string $level = 'error', int $age = 30, ?int $code = 7036, string $source = 'Service Control Manager'): array
    {
        return ['ts' => gmdate('Y-m-d\TH:i:s\Z', time() - $age), 'level' => $level, 'source' => $source, 'event_id' => $code, 'message' => $msg];
    }

    // ------------------------------------------------------------------ off by default, offered on request

    public function testOffByDefault(): void
    {
        [$c, $j] = $this->ship([self::ev()]);
        $this->assertSame([503, 'feature_disabled'], [$c, $j['code']]);
        $this->assertSame('3600', $this->h->call('POST', 'agent_logs', '{}', $this->devToken)[3]->headers['Retry-After']);
        $r = $this->announce($this->devToken, self::allCaps());
        $this->assertArrayNotHasKey('features', $r);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_log_events'));
        $this->assertFalse($this->h->module->settings()->featureOn('logs'));
    }

    public function testTheConfigIsOfferedOnlyToAnAgentThatAskedWhileTheSwitchIsOnAndASourceExists(): void
    {
        $this->switchOn(['logs']);
        $r = $this->announce($this->devToken, self::allCaps());
        $this->assertArrayNotHasKey('features', $r, 'no source configured for the platform: nothing to offer');
        $this->enableLogs();
        $r = $this->announce($this->devToken, self::allCaps());
        $this->assertSame(['logs:collect'], $r['features']);
        $cfg = $r['config']['logs'];
        $this->assertSame(['version', 'interval_s', 'batch_max', 'max_message_bytes', 'rate_per_min', 'sources'], array_keys($cfg));
        $this->assertSame([60, 200, 1024, 600], [$cfg['interval_s'], $cfg['batch_max'], $cfg['max_message_bytes'], $cfg['rate_per_min']]);
        $this->assertSame([['kind' => 'eventlog', 'channel' => 'System', 'levels' => ['critical', 'error', 'warning'], 'event_ids' => [7036], 'contains' => []]], $cfg['sources']);
        $this->assertSame($cfg['version'], $this->announce($this->devToken, self::allCaps())['config']['logs']['version'], 'the version only changes when the configuration does');
        // an agent that did not announce logs:collect (an old one) is answered exactly as before
        $caps = array_values(array_diff(self::allCaps(), ['logs:collect']));
        $old = $this->announce($this->devToken, $caps);
        $this->assertArrayNotHasKey('features', $old);
        $this->assertSame(['checks', 'collect_interval_s'], array_keys($old['config']));
        [$c, , $bare] = $this->h->checkin($this->devToken);
        $this->assertSame(['checks', 'collect_interval_s'], array_keys($bare['config']));
        $this->assertArrayNotHasKey('features', $bare);
        // the Linux device gets the Linux sources, with a journal unit and no Windows channel
        [$l, $lt] = $this->linked('L1', null, 'linux');
        $lr = $this->announce($lt, self::allCaps('linux'), 'linux');
        $this->assertSame([['kind' => 'journal', 'units' => ['ssh.service'], 'identifiers' => [], 'min_level' => 'warning', 'contains' => ['failed password']]], $lr['config']['logs']['sources']);
        $this->assertNotSame($cfg['version'], $lr['config']['logs']['version']);
        // switched off again: nothing is offered
        $this->switchOn([]);
        $this->assertArrayNotHasKey('features', $this->announce($this->devToken, self::allCaps()));
        $this->assertNotSame(0, $c);
        $this->assertNotSame('', $lt);
    }

    public function testLogIngestIsTheFirstThingShedUnderLoad(): void
    {
        $this->enableLogs();
        $this->assertSame(200, $this->ship([self::ev()])[0]);
        $this->h->module->settings()->set(['shed_level' => 1]);
        [$c, $j, $r] = $this->ship([self::ev('later')]);
        $this->assertSame([503, 'unavailable'], [$c, $j['code']]);
        $this->assertGreaterThanOrEqual(60, (int) $r->headers['Retry-After']);
        $this->assertArrayNotHasKey('features', $this->announce($this->devToken, self::allCaps()), 'the agent is told to stop collecting too');
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_log_events'));
        $this->h->module->settings()->set(['shed_level' => 0]);
        $this->assertSame(200, $this->ship([self::ev('later')])[0]);
        // jobs and enrolment are not shed
        $this->h->module->settings()->set(['shed_level' => 2]);
        $this->assertSame(200, $this->h->call('GET', 'agent_jobs', null, $this->devToken)[0]);
        $this->h->module->settings()->set(['shed_level' => 0]);
    }

    // ------------------------------------------------------------------ ingest

    public function testABatchIsStoredCleanedAndNeverDuplicated(): void
    {
        $this->enableLogs();
        $batch = [self::ev(), self::ev('Failed password for root password=hunter2', 'warning', 20, null, 'sshd.service'), self::ev("line one\nline two \x07 bell", 'critical', 10, 41), self::ev('verbose one', 'verbose', 5, 9)];
        [$c, $j] = $this->ship($batch);
        $this->assertSame([200, true, 4, 0, 0], [$c, $j['ok'], $j['accepted'], $j['dropped'], $j['duplicates']]);
        $rows = $this->h->rows('SELECT * FROM endpoint_agent_log_events ORDER BY occurred_at');
        $this->assertCount(4, $rows);
        $this->assertSame(['error', 'warning', 'critical', 'debug'], array_map(static fn ($r) => \RivetCore\Rmm\Remote\RemoteProtocol::levelName((int) $r['level']), $rows));
        $this->assertStringNotContainsString('hunter2', implode('|', array_column($rows, 'message')));
        $this->assertStringNotContainsString("\n", implode('|', array_column($rows, 'message')));
        $this->assertSame(4, (int) $this->h->one("SELECT row_count FROM endpoint_agent_log_state WHERE device_id=$this->dev"));
        // the agent never got the answer and sends the same batch again: stored once
        [$c, $j] = $this->ship($batch);
        $this->assertSame([200, 0, 4], [$c, $j['accepted'], $j['duplicates']]);
        $this->assertSame(4, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_log_events'));
        $this->assertSame(4, (int) $this->h->one("SELECT row_count FROM endpoint_agent_log_state WHERE device_id=$this->dev"));
        // entries it cannot understand are dropped and counted, never an error for the batch
        [$c, $j] = $this->ship([self::ev('ok one', 'info', 3, 1), ['ts' => 'nope', 'level' => 'error', 'message' => 'x'], ['ts' => self::ev()['ts'], 'level' => 'fatal', 'message' => 'x'], 'junk', 5, self::ev('ancient', 'error', 40 * 86400)], null, 7);
        $this->assertSame([200, 1, 5], [$c, $j['accepted'], $j['dropped']]);
        $this->assertSame(12, (int) $this->h->one("SELECT dropped_total FROM endpoint_agent_log_state WHERE device_id=$this->dev"), 'the agent\'s own drops are counted too');
        // a malformed body
        foreach ([['events' => 'x'], ['events' => ['a' => 1]], ['events' => array_fill(0, 201, self::ev())], ['events' => [], 'dropped' => -1], ['events' => [], 'dropped' => 'x'], []] as $bad) {
            $r = $this->h->api->handle($this->h->request('POST', 'agent_logs', (string) json_encode($bad), $this->devToken));
            $this->assertSame(422, $r->status, json_encode($bad));
        }
        $this->assertSame(413, $this->h->api->handle($this->h->request('POST', 'agent_logs', str_repeat(' ', 262145), $this->devToken))->status);
        $this->assertSame(401, $this->h->api->handle($this->h->request('POST', 'agent_logs', '{"events":[]}', null))->status);
        $this->assertSame(405, $this->h->api->handle($this->h->request('GET', 'agent_logs', null, $this->devToken))->status);
        $this->assertSame(5, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_log_events'));
    }

    public function testTheDeviceHasARateBudgetAndRowCap(): void
    {
        $this->enableLogs(['logs_rate_per_min' => 10, 'logs_device_max_rows' => 100]);
        $many = static fn (int $n, string $tag): array => array_map(static fn (int $i): array => self::ev("$tag entry $i", 'error', 100 + $i), range(1, $n));
        [$c, $j] = $this->ship($many(12, 'a'));
        $this->assertSame([200, 10, 2], [$c, $j['accepted'], $j['dropped']], 'ten per minute, the rest is counted and dropped');
        [, $j] = $this->ship($many(5, 'b'));
        $this->assertSame([0, 5], [$j['accepted'], $j['dropped']], 'the budget is per minute');
        $this->h->clock->advance(61);
        [, $j] = $this->ship($many(5, 'b'));
        $this->assertSame(5, $j['accepted']);
        // the row cap: a little above the cap is tolerated, then entries are refused until housekeeping trims
        $this->enableLogs(['logs_rate_per_min' => 6000, 'logs_device_max_rows' => 100]);
        $this->h->clock->advance(61);
        $this->ship($many(100, 'c'));
        $this->ship($many(100, 'd'));
        $this->assertLessThanOrEqual(120, (int) $this->h->one("SELECT row_count FROM endpoint_agent_log_state WHERE device_id=$this->dev"));
        $this->assertSame(120, (int) $this->h->one("SELECT row_count FROM endpoint_agent_log_state WHERE device_id=$this->dev"));
        [, $j] = $this->ship($many(10, 'e'));
        $this->assertSame([0, 10], [$j['accepted'], $j['dropped']]);
        $out = $this->h->module->logService()->prune();
        $this->assertSame(20, $out['device_cap']);
        $this->assertSame(100, (int) $this->h->one("SELECT row_count FROM endpoint_agent_log_state WHERE device_id=$this->dev"));
        $this->assertSame(100, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_log_events'));
        $this->h->clock->advance(61);
        $this->assertSame(10, $this->ship($many(10, 'f'))[1]['accepted'], 'after the trim there is room again');
        // the rate bucket of the endpoint
        $this->h->rateCalls = [];
        $this->h->rateOverride['agent_logs:' . $this->dev] = 2;
        $codes = array_map(fn (): int => $this->ship([])[0], range(1, 4));
        $this->assertSame([200, 200, 429, 429], $codes);
        $this->assertSame([30, 60], $this->h->rateArgs['agent_logs:' . $this->dev]);
    }

    public function testTheFleetHasAStorageCap(): void
    {
        $this->enableLogs(['logs_fleet_max_rows' => 1000, 'logs_device_max_rows' => 1000]);
        [$d2, $t2] = $this->linked('W2');
        $mk = static fn (int $n, string $tag): array => array_map(static fn (int $i): array => self::ev("$tag $i", 'error', 100 + $i), range(1, $n));
        $this->ship($mk(150, 'x'));
        $this->h->clock->advance(61);
        $this->ship($mk(150, 'y'));
        $this->h->q("UPDATE endpoint_agent_log_state SET row_count = 1090 WHERE device_id=$this->dev");   // pretend the fleet is just under the tolerated ceiling (1100)
        $this->h->clock->advance(61);
        [, $j] = $this->ship($mk(50, 'z'), $t2);
        $this->assertSame([10, 40], [$j['accepted'], $j['dropped']], 'the fleet ceiling is a cap for everyone');
        $this->assertNotSame(0, $d2);
    }

    // ------------------------------------------------------------------ retention

    public function testRetentionIsPrunedInBatchesAndTheCountersStayExact(): void
    {
        $this->enableLogs(['logs_retention_days' => 1]);
        [$d2] = $this->linked('W2');
        $old = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
        $new = gmdate('Y-m-d H:i:s', time() - 60);
        $rows = [];
        for ($i = 0; $i < 4500; ++$i) {
            $dev = $i % 3 === 0 ? $d2 : $this->dev;
            $rows[] = "($dev, '$old', '$old', 2, 's', NULL, 'old $i', UNHEX('" . substr(hash('sha256', "o$i"), 0, 32) . "'))";
        }
        for ($i = 0; $i < 50; ++$i) {
            $rows[] = "($this->dev, '$new', '$new', 2, 's', NULL, 'new $i', UNHEX('" . substr(hash('sha256', "n$i"), 0, 32) . "'))";
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            $this->h->q('INSERT INTO endpoint_agent_log_events (device_id, occurred_at, received_at, level, source, code, message, dedupe) VALUES ' . implode(',', $chunk));
        }
        $this->h->q("INSERT INTO endpoint_agent_log_state (device_id, row_count) VALUES ($this->dev, 3050), ($d2, 1500)");
        $pauses = 0;
        $out = $this->h->module->logService()->prune(function (int $us) use (&$pauses): void {
            ++$pauses;
        });
        $this->assertSame(4500, $out['retention']);
        $this->assertSame(3, $pauses, 'deleted in batches of ' . LogService::PRUNE_BATCH . ' with a pause between');
        $this->assertSame(50, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_log_events'));
        $this->assertSame(50, (int) $this->h->one("SELECT row_count FROM endpoint_agent_log_state WHERE device_id=$this->dev"));
        $this->assertSame(0, (int) $this->h->one("SELECT row_count FROM endpoint_agent_log_state WHERE device_id=$d2"));
        $this->assertSame(['pruned_logs_retention' => 0], array_intersect_key($this->h->module->housekeeping()->run(), ['pruned_logs_retention' => 1]), 'housekeeping runs it, even with the switch off');
    }

    public function testFleetCapTrimsTheOldestFirst(): void
    {
        $this->enableLogs(['logs_fleet_max_rows' => 1000, 'logs_device_max_rows' => 100000]);
        $rows = [];
        for ($i = 0; $i < 1300; ++$i) {
            $t = gmdate('Y-m-d H:i:s', time() - 5000 + $i);
            $rows[] = "($this->dev, '$t', '$t', 2, 's', NULL, 'm $i', UNHEX('" . substr(hash('sha256', "f$i"), 0, 32) . "'))";
        }
        $this->h->q('INSERT INTO endpoint_agent_log_events (device_id, occurred_at, received_at, level, source, code, message, dedupe) VALUES ' . implode(',', $rows));
        $this->h->q("INSERT INTO endpoint_agent_log_state (device_id, row_count) VALUES ($this->dev, 1300)");
        $out = $this->h->module->logService()->prune();
        $this->assertSame(300, $out['fleet_cap']);
        $this->assertSame('m 300', $this->h->one('SELECT message FROM endpoint_agent_log_events ORDER BY occurred_at LIMIT 1'), 'the oldest 300 went');
        $this->assertSame(1000, (int) $this->h->one("SELECT row_count FROM endpoint_agent_log_state WHERE device_id=$this->dev"));
    }

    // ------------------------------------------------------------------ search and scope

    private function seedSearch(): void
    {
        $this->enableLogs();
        [$this->b, $this->bToken] = $this->linked('B1', $this->h->clientB);
        $this->ship([self::ev('Disk 100% full on C_', 'error', 100, 51), self::ev('Printer ready', 'info', 200, 1, 'Spooler'), self::ev('Kernel panic', 'critical', 50, 41, 'Kernel-Power'), self::ev('Slow response', 'warning', 3000, 2, 'Spooler')]);
        $this->ship([self::ev('B only message', 'error', 40, 7)], $this->bToken);
    }

    private int $b = 0;
    private string $bToken = '';

    public function testSearchByDeviceTimeLevelSourceCodeAndText(): void
    {
        $this->seedSearch();
        $msgs = static fn (array $j): array => array_column($j['items'], 'message');
        [$c, $j] = $this->api('GET', [$this->d(), 'logs'], $this->tech, null, ['from' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
        $this->assertSame(200, $c);
        $this->assertSame(['Kernel panic', 'Disk 100% full on C_', 'Printer ready', 'Slow response'], $msgs($j), 'newest first');
        $this->assertSame(4, $j['total']);
        $first = $j['items'][0];
        $this->assertSame(['critical', 'Kernel-Power', 41, $this->dev], [$first['level'], $first['source'], $first['code'], $first['device_id']]);
        $q = fn (array $f): array => $msgs($this->api('GET', [$this->d(), 'logs'], $this->tech, null, $f)[1]);
        $this->assertSame(['Kernel panic', 'Disk 100% full on C_'], $q(['level' => 'error']), 'this severity or worse');
        $this->assertSame(['Kernel panic'], $q(['level' => 'critical']));
        $this->assertSame(['Printer ready'], $q(['source' => 'spooler', 'q' => 'ready']));
        $this->assertSame(['Disk 100% full on C_'], $q(['code' => '51']));
        $this->assertSame(['Disk 100% full on C_'], $q(['q' => '100%']), 'a percent sign is text, not a wildcard');
        $this->assertSame(['Disk 100% full on C_'], $q(['q' => 'C_']), 'so is an underscore');
        $this->assertSame([], $q(['q' => 'zzz%']), 'no wildcard leaks');
        $this->assertSame([], $q(['q' => "x' OR '1'='1"]));
        $this->assertSame(['Printer ready'], $q(['from' => gmdate('Y-m-d\TH:i:s\Z', time() - 250), 'to' => gmdate('Y-m-d\TH:i:s\Z', time() - 150)]));
        $this->assertSame(['Slow response', 'Printer ready'], array_slice(array_reverse($q(['from' => gmdate('Y-m-d\TH:i:s\Z', time() - 4000)])), 0, 2));
        $this->assertSame(['Disk 100% full on C_'], $msgs($this->api('GET', [$this->d(), 'logs'], $this->tech, null, ['limit' => '1', 'offset' => '1'])[1]));
        // a bad time is the default window, not an error
        $this->assertSame(200, $this->api('GET', [$this->d(), 'logs'], $this->tech, null, ['from' => 'garbage', 'to' => '9999-99-99'])[0]);
        $s = $this->api('GET', [$this->d(), 'logs', 'stats'], $this->tech)[1];
        $this->assertSame([4, 20000, 7], [$s['device']['rows'], $s['device_max_rows'], $s['retention_days']]);
    }

    public function testFleetSearchIsScopedToTheCallersClients(): void
    {
        $this->seedSearch();
        $fleet = fn (\RivetCore\Rmm\Authz\RmmPrincipal $who, array $q = []): array => $this->api('GET', ['logs'], $who, null, $q);
        [$c, $j] = $fleet($this->admin);
        $this->assertSame(200, $c);
        $this->assertCount(5, $j['items']);
        [, $j] = $fleet($this->scoped);
        $this->assertSame(['B only message'], array_column($j['items'], 'message'), 'a technician limited to client B sees only client B\'s entries');
        $this->assertSame([$this->b], array_unique(array_column($j['items'], 'device_id')));
        $this->assertSame(404, $fleet($this->scoped, ['device_id' => $this->d()])[0], 'a device of another client is not found');
        $this->assertSame(404, $this->api('GET', [$this->d(), 'logs'], $this->scoped)[0]);
        $this->assertSame(200, $fleet($this->scoped, ['device_id' => (string) $this->b])[0]);
        $this->assertSame(4, $fleet($this->admin, ['client_id' => (string) $this->h->clientA])[1]['total']);
        $this->assertSame(['B only message'], array_column($fleet($this->admin, ['client_id' => (string) $this->h->clientB])[1]['items'], 'message'));
        // moving a device to another client moves its logs with it: the client is stored with each entry (phase 2 of #89), so the move goes through DeviceService::transfer(), which updates them
        $this->assertTrue($this->h->module->deviceService()->transfer($this->dev, $this->h->clientB, 0, 1));
        $this->assertCount(5, $fleet($this->scoped)[1]['items']);
        // the read model for an edition page
        $this->assertSame(5, $this->h->module->readModel()->logSearch([], [$this->h->clientB])['total']);
        $this->assertSame(0, $this->h->module->readModel()->logSearch([], [])['total']);
        $this->assertSame(5, $this->h->module->readModel()->logStats()['fleet_rows']);
    }

    public function testASearchWindowIsBounded(): void
    {
        $this->seedSearch();
        $r = $this->h->module->logService()->search(['from' => '2000-01-01T00:00:00Z'], null);
        $this->assertSame(7 * 86400, strtotime($r['to']) - strtotime($r['from']), 'a fleet search spans at most a week');
        $r = $this->h->module->logService()->search(['device_id' => $this->dev, 'from' => '2000-01-01T00:00:00Z'], null);
        $this->assertSame(90 * 86400, strtotime($r['to']) - strtotime($r['from']), 'a device search at most 90 days');
        $this->assertLessThanOrEqual(500, count($this->h->module->logService()->search([], null, 99999)['items']));
    }

    // ------------------------------------------------------------------ cost

    public function testCostOfACheckinAndOfABatch(): void
    {
        $caps = self::allCaps();
        $cost = function (callable $f): int {
            $before = $this->h->counting->statements;
            $f();

            return $this->h->counting->statements - $before;
        };
        $this->switchOn([]);
        $this->announce($this->devToken, $caps);   // warm: first announce writes the capability row
        $this->announce($this->devToken, $caps);
        $off = $cost(fn () => $this->announce($this->devToken, $caps));
        $old = $cost(fn () => $this->h->checkin($this->devToken));
        $this->enableLogs();
        $on = $cost(fn () => $this->announce($this->devToken, $caps));
        $this->assertLessThanOrEqual($off + 2, $on, 'offering the log config costs at most two more statements (the remote-settings row, read once, and the capability read)');
        $this->switchOn([]);
        $this->announce($this->devToken, $caps);   // the settings cache is cold after a write
        $this->assertSame($off, $cost(fn () => $this->announce($this->devToken, $caps)), 'with the switch off a check-in costs what it did before Phase 8');
        $this->assertGreaterThan(0, $old);

        // one batch of 200 entries with no rule: the transaction, the counter row, the fleet figure, two multi-row inserts, the counter update
        $this->enableLogs();
        $batch = array_map(static fn (int $i): array => self::ev("entry $i", 'error', 100 + $i, $i), range(1, 200));
        $this->h->module->settings()->refresh();
        $n = $cost(fn () => $this->ship($batch));
        $this->assertLessThanOrEqual(12, $n, "a 200-entry batch costs $n statements");
        $this->assertSame(200, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_log_events'));
        // the same batch with ten rules that match nothing adds no statement (matching is in memory; only a match with a threshold above one counts rows)
        for ($i = 0; $i < 10; ++$i) {
            $this->rule(['name' => "r$i", 'contains' => "nomatch$i"]);
        }
        $this->h->clock->advance(61);
        $batch2 = array_map(static fn (int $i): array => self::ev("other $i", 'error', 100 + $i, 1000 + $i), range(1, 200));
        $m = $cost(fn () => $this->ship($batch2));
        $this->assertLessThanOrEqual($n + 2, $m, 'ten rules cost one read of the rule list, not a statement per entry');
    }

    // ------------------------------------------------------------------ rules and alerts

    /** @return array<string,mixed> */
    private function rule(array $over = []): array
    {
        [$c, $j] = $this->api('POST', ['logs', 'rules'], $this->admin, $over + ['name' => 'Spooler stopped', 'min_level' => 'error', 'contains' => 'spooler', 'severity' => 'fail']);
        $this->assertSame(201, $c, json_encode($j));

        return $j;
    }

    public function testRuleManagementAndItsRules(): void
    {
        $this->enableLogs();
        $r = $this->rule();
        $this->assertSame(['Spooler stopped', 'error', 'log.rule_' . $r['rule_id'], true, 0], [$r['name'], $r['min_level'], $r['check_key'], $r['enabled'], $r['client_id']]);
        foreach ([['name' => ''], ['name' => 'x', 'regex' => '.*'], ['name' => 'x', 'threshold' => 0], ['name' => 'x', 'min_level' => 'info'], ['name' => 'x', 'severity' => 'boom'], ['name' => str_repeat('a', 101)]] as $bad) {
            $this->assertSame(422, $this->api('POST', ['logs', 'rules'], $this->admin, $bad)[0], json_encode($bad));
        }
        $this->assertCount(1, $this->api('GET', ['logs', 'rules'], $this->tech)[1]['data']);
        $this->assertSame(403, $this->api('POST', ['logs', 'rules'], $this->runner, ['name' => 'x', 'contains' => 'y'])[0], 'rules need alert.manage');
        $this->assertSame(403, $this->api('GET', ['logs', 'rules'], $this->h->principal(99, 'Nobody'))[0]);
        [$c, $j] = $this->api('PATCH', ['logs', 'rules', (string) $r['rule_id']], $this->admin, ['threshold' => 3, 'window_min' => 10]);
        $this->assertSame([200, 3, 10, 'Spooler stopped'], [$c, $j['threshold'], $j['window_min'], $j['name']]);
        $this->assertSame(404, $this->api('GET', ['logs', 'rules', '9999'], $this->admin)[0]);
        $this->assertSame(404, $this->api('DELETE', ['logs', 'rules', 'x'], $this->admin)[0]);
        // client scope: an all-clients rule needs all-client access, a client rule needs that client
        $this->assertSame(403, $this->api('POST', ['logs', 'rules'], $this->scoped, ['name' => 'g', 'contains' => 'y'])[0]);
        $this->assertSame(404, $this->api('POST', ['logs', 'rules'], $this->scoped, ['name' => 'a', 'contains' => 'y', 'client_id' => $this->h->clientA])[0]);
        $mine = $this->api('POST', ['logs', 'rules'], $this->scoped, ['name' => 'b', 'contains' => 'y', 'client_id' => $this->h->clientB]);
        $this->assertSame(201, $mine[0]);
        $ids = array_column($this->api('GET', ['logs', 'rules'], $this->scoped)[1]['data'], 'rule_id');
        $this->assertSame([$mine[1]['rule_id']], $ids, 'a restricted technician sees only the rules of their own clients');
        $this->assertSame(404, $this->api('GET', ['logs', 'rules', (string) $r['rule_id']], $this->scoped)[0]);
        $this->assertSame(404, $this->api('PATCH', ['logs', 'rules', (string) $r['rule_id']], $this->scoped, ['name' => 'hijack'])[0]);
        $this->assertSame(404, $this->api('DELETE', ['logs', 'rules', (string) $r['rule_id']], $this->scoped)[0]);
        $this->assertSame('Spooler stopped', $this->api('GET', ['logs', 'rules', (string) $r['rule_id']], $this->admin)[1]['name']);
        $this->assertSame(200, $this->api('DELETE', ['logs', 'rules', (string) $mine[1]['rule_id']], $this->scoped)[0]);
        $this->assertStringContainsString('Log Rule Created', json_encode($this->h->audit->records()) ?: '');
    }

    public function testAMatchRaisesAnAlertThroughTheCheckEvaluatorAndQuietnessResolvesIt(): void
    {
        $this->enableLogs();
        $r = $this->rule();
        $key = 'log.rule_' . $r['rule_id'];
        $this->ship([self::ev('Everything is fine', 'info'), self::ev('Spooler crashed', 'warning')]);   // below the minimum level
        $this->assertSame([], $this->h->bridge->alertKeys());
        [, $j] = $this->ship([self::ev('The Spooler service terminated unexpectedly', 'error')]);
        $this->assertSame(1, $j['accepted']);
        $alerts = $this->h->bridge->alertKeys();
        $this->assertCount(1, $alerts, 'a log match is a fact: it opens the alert at once, past the failure debounce');
        $this->assertStringContainsString($key, $alerts[0]);
        $this->assertSame('fail', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE device_id=$this->dev AND check_key='$key'"));
        $this->assertStringContainsString('Spooler stopped', (string) $this->h->one("SELECT detail FROM endpoint_agent_checks WHERE device_id=$this->dev AND check_key='$key'"));
        $this->assertStringContainsString('terminated unexpectedly', (string) $this->h->one("SELECT detail FROM endpoint_agent_checks WHERE device_id=$this->dev AND check_key='$key'"));
        $ev = $this->bus->of(RmmEvent::LOG_RULE_MATCHED);
        $this->assertCount(1, $ev);
        $this->assertSame([(int) $r['rule_id'], 'Spooler stopped', 1, $key], [$ev[0]['rule_id'], $ev[0]['rule_name'], $ev[0]['match_count'], $ev[0]['alert_check_key']]);
        $this->assertSame($this->dev, $ev[0]['device_id']);
        $this->assertCount(1, $this->bus->of(RmmEvent::CHECK_FAILED), 'and the ordinary check-failed event fires');
        // more matches keep the one alert; the quiet period has not passed
        $this->h->clock->advance(600);
        $this->ship([self::ev('The Spooler service terminated unexpectedly again', 'error', 5)]);
        $this->assertCount(1, $this->h->bridge->alertKeys());
        $this->assertSame(0, $this->h->module->logRules()->sweep());
        $this->h->clock->advance(31 * 60);
        $this->assertSame(1, $this->h->module->logRules()->sweep());
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE device_id=$this->dev AND check_key='$key'"));
        $this->assertCount(1, $this->bus->of(RmmEvent::CHECK_RECOVERED));
        $this->assertSame(0, $this->h->module->logRules()->sweep());
        // housekeeping runs the sweep too
        $this->ship([self::ev('Spooler fell over', 'error', 1)]);
        $this->h->clock->advance(31 * 60);
        $this->assertSame(1, $this->h->module->housekeeping()->run()['log_rule_alerts_recovered']);
    }

    public function testACountThresholdAndAClientScopedRule(): void
    {
        $this->enableLogs();
        $r = $this->rule(['name' => 'Failed logons', 'contains' => 'failed logon', 'threshold' => 3, 'window_min' => 5, 'severity' => 'warn']);
        $key = 'log.rule_' . $r['rule_id'];
        $this->ship([self::ev('failed logon for bob', 'error', 30), self::ev('failed logon for amy', 'error', 29)]);
        $this->assertSame([], $this->h->bridge->alertKeys(), 'two of three');
        $this->ship([self::ev('failed logon for cat', 'error', 5)]);
        $this->assertCount(1, $this->h->bridge->alertKeys(), 'the window counts what earlier batches stored');
        $this->assertSame('warn', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE device_id=$this->dev AND check_key='$key'"));
        // an old match outside the window does not count
        [$b, $bt] = $this->linked('B2', $this->h->clientB);
        $this->ship([self::ev('failed logon for old', 'error', 1000), self::ev('failed logon for old2', 'error', 999)], $bt);
        $this->ship([self::ev('failed logon for new', 'error', 2)], $bt);
        $this->assertCount(1, $this->h->bridge->alertKeys());
        // a rule for one client never fires on another client's device
        $this->api('PATCH', ['logs', 'rules', (string) $r['rule_id']], $this->admin, ['threshold' => 1, 'client_id' => $this->h->clientA]);
        $this->ship([self::ev('failed logon for zed', 'error', 1)], $bt);
        $this->assertCount(1, $this->h->bridge->alertKeys());
        $this->assertNull($this->h->one("SELECT status FROM endpoint_agent_checks WHERE device_id=$b AND check_key='$key'"));
    }

    public function testDisablingOrDeletingARuleClosesItsAlerts(): void
    {
        $this->enableLogs();
        $r = $this->rule();
        $key = 'log.rule_' . $r['rule_id'];
        $this->ship([self::ev('Spooler gone', 'error')]);
        $this->assertSame('fail', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='$key'"));
        $this->assertSame(200, $this->api('PATCH', ['logs', 'rules', (string) $r['rule_id']], $this->admin, ['enabled' => false])[0]);
        $this->ship([self::ev('Spooler gone again', 'error', 2)]);
        $this->assertSame(1, $this->h->module->logRules()->sweep(), 'a disabled rule recovers at the next sweep, however recent the match');
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='$key'"));
        $this->api('PATCH', ['logs', 'rules', (string) $r['rule_id']], $this->admin, ['enabled' => true]);
        $this->ship([self::ev('Spooler gone 3', 'error', 1)]);
        $this->assertSame('fail', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='$key'"));
        $this->assertSame(200, $this->api('DELETE', ['logs', 'rules', (string) $r['rule_id']], $this->admin)[0]);
        $this->assertSame(1, $this->h->module->logRules()->sweep());
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='$key'"));
    }

    public function testRulesUseThePhase3AlertingEngineWhenItIsOn(): void
    {
        $this->enableLogs();
        $this->switchOn(['logs', 'alerting']);
        $r = $this->rule();
        // a maintenance window that suppresses the device holds the alert back, as for any check
        $win = $this->api('POST', ['maintenance'], $this->admin, ['name' => 'patching', 'mode' => 'suppress', 'kind' => 'once', 'scope_type' => 'device', 'scope_id' => $this->dev, 'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]);
        $this->assertSame(201, $win[0], json_encode($win[1]));
        $this->ship([self::ev('Spooler crashed', 'error')]);
        $this->assertSame([], $this->h->bridge->alertKeys(), 'inside a suppress window the log alert waits like every other check');
        $this->api('DELETE', ['maintenance', (string) $win[1]['window']['window_id']], $this->admin);
        $this->h->clock->advance(5);
        $this->ship([self::ev('Spooler crashed again', 'error', 1)]);
        $this->assertCount(1, $this->h->bridge->alertKeys());
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM rmm_alert_meta WHERE check_key='log.rule_{$r['rule_id']}'"), 'and it has a lifecycle record to acknowledge and escalate');
    }
}
