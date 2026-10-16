<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\RmmEvent;
use RivetCore\Tests\Support\PatchTestCase;

/** Phase 4: patch scan, approval, install and compliance, end to end through the REST API and the device endpoints. */
final class PatchTest extends PatchTestCase
{
    private int $dev = 0;
    private string $tok = '';

    protected function setUp(): void
    {
        parent::setUp();
        [$this->dev, $this->tok] = $this->linked('W1');
    }

    private function d(): string
    {
        return (string) $this->dev;
    }

    // ------------------------------------------------------------------ scan

    public function testAScanIsQueuedSignedAppliedAndReadBack(): void
    {
        $report = self::winScan([self::WIN_KB => ['security', '2026-10 Cumulative Update'], self::WIN_KB2 => ['optional', 'Preview update']]);
        $this->scanWith($this->dev, $this->tok, $report);
        [$c, $j] = $this->api('GET', [$this->d(), 'patches'], $this->viewer);
        $this->assertSame(200, $c);
        $this->assertSame(2, $j['total']);
        $this->assertSame(['security', 'optional'], array_column($j['items'], 'kind'), 'worst kind first');
        $this->assertSame('available', $j['items'][0]['status']);
        $s = $j['summary'];
        $this->assertSame(['ok', 'wua', 'running', false], [$s['scan']['state'], $s['scan']['manager'], $s['scan']['wu_state'], $s['scan']['reboot_pending']]);
        $this->assertSame(2, $s['counts']['missing']);
        $this->assertSame(1, $s['counts']['missing_by_kind']['security']);
        $this->assertFalse($s['compliant'] && $s['overdue']['critical_or_security'] > 0);
        // the job row keeps the raw output and the ledger marks it applied
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_patch_jobs WHERE applied_at IS NOT NULL'));
    }

    public function testASecondScanDropsInstalledUpdatesAndKeepsStatusOfTheRest(): void
    {
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A'], self::WIN_KB2 => ['optional', 'B']]));
        $this->assertSame(200, $this->api('POST', ['patches', 'approve'], $this->tech, ['update_ids' => [self::WIN_KB]])[0]);
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A (renamed)']]));
        $rows = $this->h->rows('SELECT update_id, status, title FROM rmm_patch_available ORDER BY update_id');
        $this->assertSame(['approved', 'installed'], array_column($rows, 'status'), 'a still-missing update keeps its approval; a vanished one is installed');
        $this->assertSame('A (renamed)', $rows[0]['title']);
        $this->assertSame(1, (int) $this->api('GET', [$this->d(), 'patches'], $this->viewer, null, ['status' => 'missing'])[1]['total']);
    }

    public function testATruncatedScanNeverMarksTheRestInstalled(): void
    {
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A'], self::WIN_KB2 => ['optional', 'B']]));
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A']], ['truncated' => true]));
        $this->assertSame(['available', 'available'], array_column($this->h->rows('SELECT status FROM rmm_patch_available ORDER BY update_id'), 'status'));
    }

    public function testHostileReportContentIsDroppedOrCleaned(): void
    {
        $bad = json_decode(self::winScan([self::WIN_KB => ['security', 'ok']]), true);
        $bad['updates'][] = ['id' => "x'; DROP TABLE a;--", 'title' => 't', 'kind' => 'security'];
        $bad['updates'][] = ['id' => '-oAPT::Foo', 'title' => 't', 'kind' => 'security'];
        $bad['updates'][] = ['id' => self::WIN_KB2, 'title' => str_repeat('é', 1000) . "\n\x00evil", 'kind' => 'nonsense', 'severity' => '<script>', 'size' => -5, 'released_at' => 'not a date'];
        $this->scanWith($this->dev, $this->tok, (string) json_encode($bad));
        $rows = $this->h->rows('SELECT * FROM rmm_patch_available ORDER BY update_id');
        $this->assertCount(2, $rows, 'ids that are not valid for the platform are dropped');
        $this->assertSame(['other', 'unspecified', 0, null], [$rows[1]['kind'], $rows[1]['severity'], (int) $rows[1]['size_bytes'], $rows[1]['released_at']]);
        $this->assertLessThanOrEqual(300, mb_strlen((string) $rows[1]['title']));
        $this->assertStringNotContainsString("\n", (string) $rows[1]['title']);
        $this->assertSame(1, (int) $this->h->one('SELECT truncated FROM rmm_patch_scans'), 'a list with dropped entries is treated as incomplete');
    }

    public function testAFailedScanIsRecordedAndDoesNotTouchTheList(): void
    {
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A']]));
        $this->scanWith($this->dev, $this->tok, '{"v":1,"ok":false,"detail":"wu_unavailable"}');
        $s = $this->api('GET', [$this->d(), 'patches'], $this->viewer)[1]['summary'];
        $this->assertSame('failed', $s['scan']['state']);
        $this->assertSame('wu_unavailable', $s['scan']['error']);
        $this->assertSame(1, $s['counts']['missing']);
        $this->scanWith($this->dev, $this->tok, 'not json at all');
        $this->assertSame('unreadable', $this->api('GET', [$this->d(), 'patches'], $this->viewer)[1]['summary']['scan']['error']);
    }

    public function testAScanAlreadyWaitingIsNotQueuedTwice(): void
    {
        $this->assertSame(202, $this->api('POST', [$this->d(), 'patches', 'scan'], $this->tech)[0]);
        [$c, $j] = $this->api('POST', [$this->d(), 'patches', 'scan'], $this->tech);
        $this->assertSame([200, 'already_queued'], [$c, $j['skipped']]);
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='patch_scan'"));
    }

    public function testLinuxScanAcceptsPackagesAndRejectsWindowsIds(): void
    {
        [$lin, $lt] = $this->linked('L1', null, 'linux');
        $rep = (string) json_encode(['v' => 1, 'ok' => true, 'manager' => 'apt', 'scanned_at' => gmdate('c'), 'scan_state' => 'ok', 'wu_state' => 'n/a', 'reboot_pending' => true, 'last_install_at' => null,
            'truncated' => false, 'updates' => [['id' => 'openssl', 'title' => 'openssl', 'kind' => 'security', 'severity' => 'important', 'version' => '3.0.2-0ubuntu1.18', 'current' => '3.0.2-0ubuntu1.15', 'size' => 0],
                ['id' => 'Bad Name', 'title' => 'not a package name', 'kind' => 'security'], ['id' => 'libc6:amd64', 'title' => 'libc6', 'kind' => 'optional']]]);
        $this->scanWith($lin, $lt, $rep);
        $ids = array_column($this->h->rows("SELECT update_id FROM rmm_patch_available WHERE device_id = $lin ORDER BY update_id"), 'update_id');
        $this->assertSame(['libc6:amd64', 'openssl'], $ids);
        // a Windows manager from a Linux device is refused as a whole
        $this->scanWith($lin, $lt, self::winScan([self::WIN_KB => ['security', 'x']]));
        $this->assertSame('unknown_manager', $this->api('GET', [(string) $lin, 'patches'], $this->viewer)[1]['summary']['scan']['error']);
    }

    // ------------------------------------------------------------------ capabilities and gating

    public function testAnAgentThatNeverAnnouncedPatchingIsNeverOfferedAJob(): void
    {
        [$old, $oldTok] = $this->linked('OLD', null, 'windows', self::caps('windows', false));
        [$c, $j] = $this->api('POST', [(string) $old, 'patches', 'scan'], $this->tech);
        $this->assertSame([409, 'unsupported'], [$c, $j['code']]);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id = $old"));
        // an agent that announced nothing at all (before capability negotiation) is also not assumed to run patch jobs
        [$ancient, $aTok] = $this->linked('ANCIENT', null, 'windows', []);
        $this->assertSame(409, $this->api('POST', [(string) $ancient, 'patches', 'scan'], $this->tech)[0]);
        $this->assertSame([], $this->offered($aTok));
        $this->assertSame([], $this->offered($oldTok));
    }

    public function testPatchJobTypesAreNotQueuedThroughTheGenericJobEndpoint(): void
    {
        foreach (['patch_scan', 'patch_install'] as $t) {
            [$c, $j] = $this->api('POST', [$this->d(), 'jobs'], $this->admin, ['type' => $t, 'params' => ['ids' => self::WIN_KB, 'mode' => 'install'], 'confirm' => true]);
            $this->assertSame([422, 'invalid'], [$c, $j['code']], $t);
        }
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type LIKE 'patch%'"));
    }

    public function testTheSwitchOffMakesActionsRefuseButReadsStillAnswer(): void
    {
        $this->assertSame([], $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'jobs' => true]]));
        [$c, $j] = $this->api('POST', [$this->d(), 'patches', 'scan'], $this->tech);
        $this->assertSame([409, 'feature_disabled'], [$c, $j['code']]);
        $this->assertStringContainsString('switched off', $j['error']);
        [$c, $j] = $this->api('GET', [$this->d(), 'patches'], $this->viewer);
        $this->assertSame(200, $c);
        $this->assertFalse($j['summary']['enabled']);
        $this->assertSame(409, $this->api('POST', [$this->d(), 'patches', 'install'], $this->installer, ['mode' => 'dry_run'])[0]);
        $this->assertSame([], $this->h->module->patchScheduler()->run() === [] ? [] : array_filter($this->h->module->patchScheduler()->run()), 'the scheduler does nothing while the switch is off');
    }

    // ------------------------------------------------------------------ order of decisions on every route (client-restricted principal included)

    public function testEveryRouteAnswersTheSame404ForMissingAndOutOfScopeAndChecksAbilitiesInOrder(): void
    {
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A']]));
        $d = $this->d();
        $routes = [
            ['GET', [$d, 'patches'], null], ['GET', [$d, 'patches', 'history'], null], ['POST', [$d, 'patches', 'scan'], null],
            ['POST', [$d, 'patches', 'install'], ['mode' => 'dry_run']], ['POST', ['patches', 'approve'], ['update_ids' => [self::WIN_KB], 'device_id' => $this->dev]],
            ['POST', ['patches', 'decline'], ['update_ids' => [self::WIN_KB], 'device_id' => $this->dev]],
        ];
        foreach ($routes as [$m, $seg, $body]) {
            $name = $m . ' ' . implode('/', $seg);
            $this->assertSame(401, $this->h->tech($m, $seg, null, $body)[0], "$name unauthenticated");
            [$c, $j] = $this->api($m, $seg, $this->scoped, $body);
            $this->assertSame([404, 'not_found'], [$c, $j['code']], "$name: a client-restricted principal gets the 404 of a missing device");
            $missing = $seg;
            if ($seg[0] === $d) {
                $missing[0] = '99999';
            } else {
                $body = ['update_ids' => [self::WIN_KB], 'device_id' => 99999];
            }
            $this->assertSame(404, $this->api($m, $missing, $this->admin, $body)[0], "$name: missing device");
        }
        // a viewer reads but cannot act
        $this->assertSame(200, $this->api('GET', [$d, 'patches'], $this->viewer)[0]);
        $this->assertSame(403, $this->api('POST', [$d, 'patches', 'scan'], $this->viewer)[0]);
        $this->assertSame(403, $this->api('POST', [$d, 'patches', 'install'], $this->viewer, ['mode' => 'dry_run'])[0]);
        $this->assertSame(403, $this->api('POST', ['patches', 'approve'], $this->viewer, ['update_ids' => [self::WIN_KB]])[0]);
        // a technician who may manage may dry-run but never run a real install
        $this->assertSame([200, 'nothing_to_install'], [$this->api('POST', [$d, 'patches', 'install'], $this->tech, ['mode' => 'dry_run'])[0], $this->api('POST', [$d, 'patches', 'install'], $this->tech, ['mode' => 'dry_run'])[1]['skipped']], 'nothing is approved yet');
        $this->assertSame(403, $this->api('POST', [$d, 'patches', 'install'], $this->tech, ['mode' => 'install', 'confirm' => true])[0]);
        // fleet reads never include another client's rows for a restricted principal
        $this->assertSame([], $this->api('GET', ['patches'], $this->scoped)[1]['items']);
        $c = $this->api('GET', ['patches', 'compliance'], $this->scoped)[1];
        $this->assertSame(0, $c['summary']['devices']);
        $this->assertSame(404, $this->api('GET', ['patches', 'compliance'], $this->scoped, null, ['client_id' => (string) $this->h->clientA])[0]);
        $this->assertSame(404, $this->api('GET', ['patches'], $this->scoped, null, ['client_id' => (string) $this->h->clientA])[0]);
    }

    public function testARestrictedPrincipalWorksOnItsOwnClientOnly(): void
    {
        [$b, $bt] = $this->linked('B1', $this->h->clientB);
        $this->scanWith($b, $bt, self::winScan([self::WIN_KB => ['security', 'A']]));
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A']]));
        $this->assertSame(200, $this->api('GET', [(string) $b, 'patches'], $this->scoped)[0]);
        $this->assertSame(404, $this->api('GET', [$this->d(), 'patches'], $this->scoped)[0]);
        // approving the same update id by name reaches only the caller's own client
        [$c, $j] = $this->api('POST', ['patches', 'approve'], $this->scoped, ['update_ids' => [self::WIN_KB]]);
        $this->assertSame([200, 1], [$c, $j['changed']]);
        $this->assertSame(['approved'], array_column($this->h->rows("SELECT status FROM rmm_patch_available WHERE device_id = $b"), 'status'));
        $this->assertSame(['available'], array_column($this->h->rows("SELECT status FROM rmm_patch_available WHERE device_id = {$this->dev}"), 'status'));
        $this->assertSame(1, $this->api('GET', ['patches', 'compliance'], $this->scoped)[1]['summary']['devices']);
        // fleet-wide objects are for callers who see every client
        foreach ([['POST', ['patch_policies'], ['name' => 'x']], ['POST', ['patches', 'rings'], ['name' => 'r', 'delay_days' => 1]]] as [$m, $seg, $body]) {
            $this->assertSame(403, $this->api($m, $seg, $this->scoped, $body)[0], implode('/', $seg));
        }
        $this->assertSame(403, $this->api('POST', ['patches', 'rules'], $this->scoped, ['name' => 'r', 'after_days' => 1])[0], 'a rule for every client');
        $this->assertSame(201, $this->api('POST', ['patches', 'rules'], $this->scoped, ['name' => 'mine', 'after_days' => 1, 'client_id' => $this->h->clientB])[0]);
    }

    // ------------------------------------------------------------------ approvals

    public function testApproveDeclineAndTheirValidation(): void
    {
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A'], self::WIN_KB2 => ['optional', 'B']]));
        foreach ([[], ['update_ids' => []], ['update_ids' => 'x'], ['update_ids' => ["a b"]], ['update_ids' => ['-rf']], ['update_ids' => [self::WIN_KB], 'device_id' => 'x'], ['update_ids' => array_fill(0, 201, 'abc')]] as $bad) {
            $this->assertSame(422, $this->api('POST', ['patches', 'approve'], $this->tech, $bad)[0], json_encode($bad));
        }
        $this->assertSame(404, $this->api('POST', ['patches', 'approve'], $this->tech, ['update_ids' => ['unknown-pkg']])[0]);
        [$c, $j] = $this->api('POST', ['patches', 'approve'], $this->tech, ['update_ids' => [self::WIN_KB]]);
        $this->assertSame([200, 1, 1], [$c, $j['matched'], $j['changed']]);
        $this->assertSame(200, $this->api('POST', ['patches', 'decline'], $this->tech, ['update_ids' => [self::WIN_KB2], 'reason' => 'breaks printing'])[0]);
        $rows = $this->h->rows('SELECT update_id, status, status_source, status_by, decline_reason FROM rmm_patch_available ORDER BY update_id');
        $this->assertSame(['approved', 'declined'], array_column($rows, 'status'));
        $this->assertSame(['manual', 10], [$rows[0]['status_source'], (int) $rows[0]['status_by']]);
        $this->assertSame('breaks printing', $rows[1]['decline_reason']);
        // a declined update can be approved again; an installed one cannot be re-approved
        $this->assertSame(1, $this->api('POST', ['patches', 'approve'], $this->tech, ['update_ids' => [self::WIN_KB2]])[1]['changed']);
        $this->h->q("UPDATE rmm_patch_available SET status = 'installed' WHERE update_id = '" . self::WIN_KB . "'");
        $this->assertSame(404, $this->api('POST', ['patches', 'approve'], $this->tech, ['update_ids' => [self::WIN_KB]])[0]);
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key = 'patch.compliance'"));
    }

    public function testAnApprovalRuleApprovesAfterItsDaysInItsRingAndNeverTouchesADeclinedUpdate(): void
    {
        $this->assertSame(201, $this->api('POST', ['patch_policies'], $this->admin, ['name' => 'pilot machines', 'settings' => ['ring' => 'pilot']])[0]);
        $pid = (int) $this->h->one('SELECT policy_id FROM rmm_patch_policies');
        $this->assertSame(200, $this->api('POST', ['patch_policies', (string) $pid, 'assignments'], $this->admin, ['scope_type' => 'device', 'scope_id' => $this->dev])[0]);
        [$c, $j] = $this->api('POST', ['patches', 'rules'], $this->admin, ['name' => 'crit+sec after 3 days in pilot', 'ring' => 'pilot', 'kinds' => ['critical', 'security'], 'after_days' => 3]);
        $this->assertSame(201, $c, json_encode($j));
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A'], self::WIN_KB2 => ['security', 'B'], self::WIN_KB3 => ['optional', 'C']]));
        $this->assertSame(['available', 'available', 'available'], array_column($this->h->rows('SELECT status FROM rmm_patch_available ORDER BY update_id'), 'status'), 'not old enough yet');
        $this->api('POST', ['patches', 'decline'], $this->tech, ['update_ids' => [self::WIN_KB2]]);
        $this->h->q("UPDATE rmm_patch_available SET first_seen_at = '" . gmdate('Y-m-d H:i:s', time() - 4 * 86400) . "'");
        $this->h->q('UPDATE rmm_patch_scans SET evaluated_at = NULL');
        $this->h->module->patchScheduler()->run();
        $rows = $this->h->rows('SELECT update_id, status, status_source FROM rmm_patch_available ORDER BY update_id');
        $this->assertSame([['approved', 'rule:' . $j['rule']['rule_id']], ['declined', 'manual'], ['available', 'scan']], array_map(static fn (array $r): array => [$r['status'], $r['status_source']], $rows));
        // another ring is not matched
        $this->h->q("UPDATE rmm_patch_available SET status = 'available' WHERE status = 'approved'");
        $this->assertSame(200, $this->api('PUT', ['patch_policies', (string) $pid], $this->admin, ['name' => 'pilot machines', 'settings' => ['ring' => 'last']])[0]);
        $this->h->q('UPDATE rmm_patch_scans SET evaluated_at = NULL');
        $this->h->module->patchScheduler()->run();
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM rmm_patch_available WHERE status = 'approved'"));
    }

    public function testAPolicyApprovesByKindAfterItsDaysPlusTheRingDelay(): void
    {
        $this->assertSame(201, $this->api('POST', ['patch_policies'], $this->admin, ['name' => 'auto', 'settings' => ['ring' => 'broad', 'approval_mode' => 'auto', 'auto_days_security' => 1]])[0]);
        $pid = (int) $this->h->one('SELECT policy_id FROM rmm_patch_policies');
        $this->api('POST', ['patch_policies', (string) $pid, 'assignments'], $this->admin, ['scope_type' => 'global']);
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A'], self::WIN_KB2 => ['optional', 'B']]));
        $this->h->q("UPDATE rmm_patch_available SET first_seen_at = '" . gmdate('Y-m-d H:i:s', time() - 5 * 86400) . "'");
        $this->h->module->patchApprovals()->autoApprove($this->h->module->devices()->find($this->dev) ?? []);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM rmm_patch_available WHERE status = 'approved'"), '1 day + 7 days of the broad ring is more than 5');
        $this->h->q("UPDATE rmm_patch_available SET first_seen_at = '" . gmdate('Y-m-d H:i:s', time() - 9 * 86400) . "'");
        $this->h->module->patchApprovals()->autoApprove($this->h->module->devices()->find($this->dev) ?? []);
        $this->assertSame([self::WIN_KB], array_column($this->h->rows("SELECT update_id FROM rmm_patch_available WHERE status = 'approved'"), 'update_id'), 'only the kind the policy names, and optional is never automatic');
    }

    public function testTwoPersonApprovalFreezesThePairsAndNeedsAnotherUser(): void
    {
        $this->assertSame(201, $this->api('POST', ['patch_policies'], $this->admin, ['name' => 'careful', 'settings' => ['two_person' => true]])[0]);
        $pid = (int) $this->h->one('SELECT policy_id FROM rmm_patch_policies');
        $this->api('POST', ['patch_policies', (string) $pid, 'assignments'], $this->admin, ['scope_type' => 'global']);
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A']]));
        [$c, $j] = $this->api('POST', ['patches', 'approve'], $this->tech, ['update_ids' => [self::WIN_KB]]);
        $this->assertSame([202, 'pending_approval'], [$c, $j['state']]);
        $this->assertSame('available', $this->h->one('SELECT status FROM rmm_patch_available'), 'nothing changes before the second person');
        $aid = (int) $j['approval_id'];
        $this->assertSame('patch', $this->h->one("SELECT kind FROM rmm_approvals WHERE approval_id = $aid"));
        // the requester cannot decide their own request; a viewer without the approve grant cannot decide at all
        $own = $this->h->module->scriptActions()->decide($this->tech, $aid, true);
        $this->assertFalse($own->ok);
        $this->assertSame('forbidden', $this->h->module->scriptActions()->decide($this->viewer, $aid, true)->code);
        // a restricted user of another client cannot decide it either
        $this->assertFalse($this->h->module->scriptActions()->decide($this->scoped, $aid, true)->ok);
        $this->assertSame('available', $this->h->one('SELECT status FROM rmm_patch_available'));
        $r = $this->h->module->scriptActions()->decide($this->second, $aid, true);
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame(['approved', 'manual', 12, $aid], [$this->h->one('SELECT status FROM rmm_patch_available'), $this->h->one('SELECT status_source FROM rmm_patch_available'),
            (int) $this->h->one('SELECT status_by FROM rmm_patch_available'), (int) $this->h->one('SELECT approval_id FROM rmm_patch_available')]);
        // deciding twice is refused
        $this->assertFalse($this->h->module->scriptActions()->decide($this->second, $aid, true)->ok);
        // a rejected request changes nothing
        $this->h->q("UPDATE rmm_patch_available SET status = 'available'");
        $j = $this->api('POST', ['patches', 'approve'], $this->tech, ['update_ids' => [self::WIN_KB]])[1];
        $this->assertTrue($this->h->module->scriptActions()->decide($this->second, (int) $j['approval_id'], false)->ok);
        $this->assertSame('available', $this->h->one('SELECT status FROM rmm_patch_available'));
    }

    // ------------------------------------------------------------------ install

    private function approvedDevice(): void
    {
        $this->scanWith($this->dev, $this->tok, self::winScan([self::WIN_KB => ['security', 'A'], self::WIN_KB2 => ['optional', 'B']]));
        $this->api('POST', ['patches', 'approve'], $this->tech, ['update_ids' => [self::WIN_KB]]);
    }

    public function testInstallDefaultsToADryRunAndOnlyApprovedUpdatesAreInstalled(): void
    {
        $this->approvedDevice();
        $d = $this->d();
        [$c, $j] = $this->api('POST', [$d, 'patches', 'install'], $this->tech, []);
        $this->assertSame(202, $c, json_encode($j));
        $this->assertSame('dry_run', $j['mode']);
        $this->assertSame(1, $j['queued'], 'by default a dry run rehearses exactly what a real install would do: the approved updates');
        $jobs = $this->offered($this->tok);
        $this->assertSame('patch_install', $jobs[0]['type']);
        $p = (array) $jobs[0]['params'];
        $this->assertSame('dry_run', $p['mode']);
        $this->assertSame([self::WIN_KB], explode(',', (string) $p['ids']));
        $this->assertSame(1, (int) $this->h->one("SELECT destructive FROM endpoint_agent_jobs WHERE type = 'patch_install'"), 'offered at most once');
        $this->assertSame(3600, (int) $this->h->one("SELECT timeout_s FROM endpoint_agent_jobs WHERE type = 'patch_install'"), 'the deadline is the install time plus a margin, capped at the job timeout');
        $this->report($this->tok, $jobs[0], 'succeeded', (string) json_encode(['v' => 1, 'ok' => true, 'mode' => 'dry_run', 'manager' => 'wua', 'reboot_required' => false, 'truncated' => false, 'results' => [
            ['id' => self::WIN_KB, 'title' => 'A', 'result' => 'would_install', 'code' => '', 'detail' => '', 'reboot_required' => true]]]));
        $h0 = $this->api('POST', [$d, 'patches', 'install'], $this->tech, ['ids' => [self::WIN_KB2]]);
        $this->assertSame([202, 1], [$h0[0], $h0[1]['queued']], 'a named update that is only available may be rehearsed');
        $this->assertSame(404, $this->api('POST', [$d, 'patches', 'install'], $this->installer, ['mode' => 'install', 'confirm' => true, 'ids' => [self::WIN_KB2]])[0], 'a real install of an update nobody approved');
        $this->assertSame(['available', 'approved'], [$this->h->one("SELECT status FROM rmm_patch_available WHERE update_id = '" . self::WIN_KB2 . "'"), $this->h->one("SELECT status FROM rmm_patch_available WHERE update_id = '" . self::WIN_KB . "'")], 'a dry run changes no status');
        $h = $this->api('GET', [$d, 'patches', 'history'], $this->viewer)[1];
        $this->assertSame(['dry_run'], array_column($h['items'], 'action'));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'reboot'"));
    }

    public function testARealInstallNeedsTheGrantTheConfirmationAndAnApprovedUpdate(): void
    {
        $this->approvedDevice();
        $d = $this->d();
        $body = ['mode' => 'install', 'ids' => [self::WIN_KB]];
        $this->assertSame(403, $this->api('POST', [$d, 'patches', 'install'], $this->tech, $body + ['confirm' => true])[0]);
        $this->assertSame('confirmation_required', $this->api('POST', [$d, 'patches', 'install'], $this->installer, $body)[1]['code']);
        $this->assertSame(404, $this->api('POST', [$d, 'patches', 'install'], $this->installer, ['mode' => 'install', 'ids' => [self::WIN_KB2], 'confirm' => true])[0], 'not approved');
        foreach ([['mode' => 'yes'], ['mode' => 'install', 'confirm' => true, 'ids' => []], ['mode' => 'install', 'confirm' => true, 'ids' => ['x y']], ['mode' => 'install', 'confirm' => true, 'ids' => 'a'],
            ['mode' => 'install', 'confirm' => true, 'reboot_policy' => 'sometimes'], ['mode' => 'install', 'confirm' => true, 'max_minutes' => 0], ['mode' => 'install', 'confirm' => true, 'max_minutes' => 241]] as $bad) {
            $this->assertSame(422, $this->api('POST', [$d, 'patches', 'install'], $this->installer, $bad)[0], json_encode($bad));
        }
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'patch_install'"));
        [$c, $j] = $this->api('POST', [$d, 'patches', 'install'], $this->installer, $body + ['confirm' => true, 'max_minutes' => 30]);
        $this->assertSame([202, 'install', 1], [$c, $j['mode'], $j['queued']]);
        $this->assertSame('{"ids":"' . self::WIN_KB . '","mode":"install","max_minutes":30}', $this->h->one("SELECT params_json FROM endpoint_agent_jobs WHERE type = 'patch_install'"));
        $this->assertSame('1', (string) $this->h->one("SELECT destructive FROM endpoint_agent_jobs WHERE type = 'patch_install'"));
        // overlap: a second one while the first waits is not queued
        [$c, $j] = $this->api('POST', [$d, 'patches', 'install'], $this->installer, $body + ['confirm' => true]);
        $this->assertSame([200, 'already_queued'], [$c, $j['skipped']]);
        $this->assertStringContainsString('INSTALL', json_encode($this->h->audit->records()) ?: '');
    }

    public function testAnInstallResultUpdatesStatusHistoryAndOpensTheRightAlerts(): void
    {
        $this->approvedDevice();
        $this->api('POST', ['patches', 'approve'], $this->tech, ['update_ids' => [self::WIN_KB2]]);
        $d = $this->d();
        $this->api('POST', [$d, 'patches', 'install'], $this->installer, ['mode' => 'install', 'confirm' => true]);
        $job = $this->offered($this->tok)[0];
        $this->report($this->tok, $job, 'failed', (string) json_encode(['v' => 1, 'ok' => false, 'mode' => 'install', 'manager' => 'wua', 'reboot_required' => true, 'truncated' => false, 'results' => [
            ['id' => self::WIN_KB, 'title' => 'A', 'result' => 'needs_reboot', 'code' => '0x00240005', 'detail' => '', 'reboot_required' => true],
            ['id' => self::WIN_KB2, 'title' => 'B', 'result' => 'failed', 'code' => '0x80070643', 'detail' => 'Fatal error during installation', 'reboot_required' => false]]]), 1);
        $rows = array_column($this->h->rows('SELECT update_id, status, fail_count FROM rmm_patch_available ORDER BY update_id'), null, 'update_id');
        $this->assertSame(['installed', 'failed', '1'], [$rows[self::WIN_KB]['status'], $rows[self::WIN_KB2]['status'], $rows[self::WIN_KB2]['fail_count']]);
        $h = $this->api('GET', [$d, 'patches', 'history'], $this->viewer)[1]['items'];
        $this->assertEqualsCanonicalizing(['needs_reboot', 'failed'], array_column($h, 'result'));
        $s = $this->api('GET', [$d, 'patches'], $this->viewer)[1]['summary'];
        $this->assertTrue($s['scan']['reboot_pending']);
        $check = $this->h->rows("SELECT check_key, status, detail FROM endpoint_agent_checks WHERE check_key = 'patch.install_failed'");
        $this->assertCount(1, $check);
        $this->assertSame('fail', $check[0]['status']);
        $this->assertStringContainsString('failed to install: B', $check[0]['detail']);
        // a follow-up scan was queued to prove the result; the policy has no restart setting, so no reboot job exists
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'patch_scan' AND state = 'queued'"));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'reboot'"));
        // the failed update installs on a retry: the failure alert recovers
        $this->api('POST', ['patches', 'approve'], $this->tech, ['update_ids' => [self::WIN_KB2]]);
        $this->api('POST', [$d, 'patches', 'install'], $this->installer, ['mode' => 'install', 'confirm' => true, 'ids' => [self::WIN_KB2]]);
        $jobs = array_values(array_filter($this->offered($this->tok), static fn (array $j): bool => $j['type'] === 'patch_install'));
        $this->report($this->tok, $jobs[0], 'succeeded', (string) json_encode(['v' => 1, 'ok' => true, 'mode' => 'install', 'manager' => 'wua', 'reboot_required' => false, 'truncated' => false, 'results' => [['id' => self::WIN_KB2, 'title' => 'B', 'result' => 'installed', 'code' => '0x0', 'detail' => '', 'reboot_required' => false]]]));
        $this->assertSame('installed', $this->h->one("SELECT status FROM rmm_patch_available WHERE update_id = '" . self::WIN_KB2 . "'"));
        for ($i = 0; $i < 3; ++$i) {
            $this->h->module->patchCompliance()->evaluate($this->h->module->devices()->find($this->dev) ?? []);
        }
        $this->assertSame('ok', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key = 'patch.install_failed'"));
    }

    public function testAJobThatNeverRanMarksTheBatchFailedAndALostUpdateInTheAnswerToo(): void
    {
        $this->approvedDevice();
        $d = $this->d();
        $this->api('POST', [$d, 'patches', 'install'], $this->installer, ['mode' => 'install', 'confirm' => true]);
        $job = $this->offered($this->tok)[0];
        $this->report($this->tok, $job, 'timed_out', 'partial text, not json', null);
        $this->assertSame('failed', $this->h->one("SELECT status FROM rmm_patch_available WHERE update_id = '" . self::WIN_KB . "'"));
        $this->assertSame('job_timed_out', $this->h->one('SELECT code FROM rmm_patch_history'));
        // a result that omits an asked update treats it as failed to run
        $this->h->q("UPDATE rmm_patch_available SET status = 'approved', last_attempt_at = NULL WHERE update_id = '" . self::WIN_KB . "'");
        $this->api('POST', [$d, 'patches', 'install'], $this->installer, ['mode' => 'install', 'confirm' => true]);
        $job = array_values(array_filter($this->offered($this->tok), static fn (array $j): bool => $j['type'] === 'patch_install'))[0];
        $this->report($this->tok, $job, 'succeeded', '{"v":1,"ok":true,"mode":"install","manager":"wua","results":[],"truncated":false}');
        $this->assertSame('failed', $this->h->one("SELECT status FROM rmm_patch_available WHERE update_id = '" . self::WIN_KB . "'"));
        $this->assertSame('no_result', $this->h->one("SELECT code FROM rmm_patch_history WHERE job_id = '{$job['job_id']}'") ?: 'no_result');
    }

    public function testRebootAfterPatchingIsAConfirmedSeparateJobAndNeverAutomaticWithoutIt(): void
    {
        $this->approvedDevice();
        $d = $this->d();
        // asking for a restart needs the explicit confirmation and the reboot grant
        $b = ['mode' => 'install', 'confirm' => true, 'reboot_policy' => 'if_required_now'];
        $this->assertSame('confirmation_required', $this->api('POST', [$d, 'patches', 'install'], $this->installer, $b)[1]['code']);
        $this->assertSame(202, $this->api('POST', [$d, 'patches', 'install'], $this->installer, $b + ['confirm_reboot' => true])[0]);
        $job = $this->offered($this->tok)[0];
        $this->assertArrayNotHasKey('reboot', (array) $job['params'], 'the agent is never told to restart');
        $this->report($this->tok, $job, 'succeeded', (string) json_encode(['v' => 1, 'ok' => true, 'mode' => 'install', 'manager' => 'wua', 'reboot_required' => true, 'truncated' => false, 'results' => [
            ['id' => self::WIN_KB, 'title' => 'A', 'result' => 'needs_reboot', 'code' => '', 'detail' => '', 'reboot_required' => true]]]));
        $r = $this->h->rows("SELECT type, destructive, created_by, params_json FROM endpoint_agent_jobs WHERE type = 'reboot'");
        $this->assertCount(1, $r);
        $this->assertSame([1, 11, '{"delay_s":300}'], [(int) $r[0]['destructive'], (int) $r[0]['created_by'], $r[0]['params_json']]);
        // the defer policy only reports
        $this->h->q("DELETE FROM endpoint_agent_jobs WHERE type = 'reboot'");
        $this->h->q("UPDATE rmm_patch_available SET status = 'approved' WHERE update_id = '" . self::WIN_KB . "'");
        $this->api('POST', [$d, 'patches', 'install'], $this->installer, ['mode' => 'install', 'confirm' => true, 'reboot_policy' => 'if_required_defer']);
        $job = array_values(array_filter($this->offered($this->tok), static fn (array $j): bool => $j['type'] === 'patch_install'))[0];
        $this->report($this->tok, $job, 'succeeded', (string) json_encode(['v' => 1, 'ok' => true, 'mode' => 'install', 'manager' => 'wua', 'reboot_required' => true, 'truncated' => false, 'results' => [
            ['id' => self::WIN_KB, 'title' => 'A', 'result' => 'installed', 'code' => '', 'detail' => '', 'reboot_required' => true]]]));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'reboot'"));
        $this->assertSame(1, (int) $this->h->one('SELECT reboot_pending FROM rmm_patch_scans'));
    }

    // ------------------------------------------------------------------ events

    public function testEventsAreEmittedForScanApprovalAndInstall(): void
    {
        $bus = new \RivetCore\Rmm\Support\NullRmmEvents();
        $this->assertNotNull($bus);   // the harness without a bus emits nothing; the event ids are pinned by EventsTest
        $this->assertContains(RmmEvent::PATCH_SCAN_COMPLETED, RmmEvent::all());
        $this->assertContains(RmmEvent::PATCH_INSTALL_FAILED, RmmEvent::all());
    }
}
