<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Mesh\MeshCookie;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Remote\BackgroundSessionRetention;
use RivetCore\Rmm\Remote\BackgroundSettingsService;
use RivetCore\Rmm\Remote\DbBackgroundModeConfig;
use RivetCore\Testing\InMemoryRmmAudit;
use RivetCore\Testing\InMemoryRmmEvents;
use RivetCore\Tests\Support\MockMeshServer;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\RoleMatrixPolicy;
use RivetCore\Tests\Support\StubBackgroundConfig;

/**
 * Governance of Background Mode on the scratch database: audited, admin-only policy changes that fail closed, the `rmm.background.*` events (no secrets), and the
 * retention of ended session rows (active sessions and audit rows are never pruned).
 */
final class BackgroundGovernanceTest extends RmmTestCase
{
    /** @var array<string,RmmPrincipal> */
    private array $who = [];
    private ?MockMeshServer $mesh = null;
    private InMemoryRmmEvents $bus;
    private int $dev = 0;

    protected function makeHarness(): RmmHarness
    {
        $p = new RoleMatrixPolicy();
        $p->addUser(1, 3, 3, 1, admin: true);
        $p->addUser(17, 3, 3, 2, background: true);
        $p->addUser(18, 3, 3, 2);
        $this->who = ['admin' => new RmmPrincipal(1, 'Admin'), 'bgtech' => new RmmPrincipal(17, 'BgTech'), 'nobg' => new RmmPrincipal(18, 'NoBg')];
        $this->bus = new InMemoryRmmEvents();

        return new RmmHarness(policy: $p, options: ['background_groups' => new StubBackgroundConfig('', '', [], ['Ops' => [17, 18]])], events: $this->bus);
    }

    protected function tearDown(): void
    {
        $this->mesh?->stop();
        parent::tearDown();
    }

    private function launchSetup(): void
    {
        $this->mesh = new MockMeshServer();
        $this->h->enable();
        $this->assertTrue($this->h->module->admin()->saveMesh($this->who['admin'], ['mesh_enabled' => 1, 'mesh_url' => $this->mesh->url(), 'mesh_login_key' => MeshCookie::newLoginKey(), 'mesh_account_template' => 'rivetit-support'])->ok);
        $tok = $this->h->token($this->h->clientA, 24, 50);
        $this->h->asset(['name' => 'GV-PC', 'serial' => 'SER-GV', 'client_id' => $this->h->clientA]);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-GV', 'hostname' => 'GV-PC']));
        $this->dev = (int) $j['device_id'];
        $this->assertTrue($this->h->module->devices()->setMeshNode($this->dev, 'node//' . str_repeat('A', 24) . $this->dev, 1));
        $this->h->checkin($j['device_token']);
        $svc = $this->h->module->backgroundSettings();
        $this->assertTrue($svc->setPolicyMode($this->who['admin'], 'internal')->ok);
        $this->assertTrue($svc->setInternalGroup($this->who['admin'], 'Ops')->ok);
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->who['admin'], ['features' => ['remote_tools' => true, 'background_mode' => true, 'remote_console' => true], 'accept_background_risk' => true])->ok);
    }

    /** @return list<array{action:string,description:string,client_id:int,entity_id:int}> */
    private function rows(string $prefix): array
    {
        return array_values(array_filter($this->h->audit->records(), static fn (array $r): bool => str_starts_with($r['action'], $prefix)));
    }

    // ------------------------------------------------------------------ a) audited policy changes

    public function testAnAdministratorChangeIsAuditedWithOldAndNewValueAndWho(): void
    {
        $svc = $this->h->module->backgroundSettings();
        $this->assertTrue($svc->setPolicyMode($this->who['admin'], 'internal')->ok);
        $this->assertTrue($svc->setPolicyMode($this->who['admin'], 'per_client')->ok);
        $this->assertSame('per_client', $this->h->module->backgroundConfig()->policyMode());
        $rows = $this->rows('Background Settings Changed');
        $this->assertCount(2, $rows);
        $this->assertStringContainsString("user 1 (Admin) changed policy_mode: 'none' -> 'internal'", $rows[0]['description']);
        $this->assertStringContainsString("'internal' -> 'per_client'", $rows[1]['description']);

        $this->assertTrue($svc->setClientPolicy($this->who['admin'], $this->h->clientA, 'notify')->ok);
        $this->assertTrue($svc->setClientPolicy($this->who['admin'], $this->h->clientA, null)->ok);
        $this->assertNull($this->h->module->backgroundConfig()->clientPolicy($this->h->clientA));
        $d = array_column($this->rows('Background Settings Changed'), 'description');
        $this->assertStringContainsString("'none' -> 'notify'", $d[2]);
        $this->assertStringContainsString("'notify' -> 'none'", $d[3]);
    }

    public function testNonAdministratorsChangeNothingEvenWithTheBackgroundAbility(): void
    {
        $svc = $this->h->module->backgroundSettings();
        foreach (['bgtech', 'nobg'] as $u) {
            $this->assertSame(403, $svc->setPolicyMode($this->who[$u], 'internal')->http);
            $this->assertSame(403, $svc->setInternalGroup($this->who[$u], 'Ops')->http);
            $this->assertSame(403, $svc->setClientPolicy($this->who[$u], $this->h->clientA, 'allow')->http);
        }
        $this->assertSame('', $this->h->module->backgroundConfig()->policyMode(), 'default is deny: nothing configured');
        $this->assertSame('', $this->h->module->backgroundConfig()->internalGroup());
        $this->assertNull($this->h->module->backgroundConfig()->clientPolicy($this->h->clientA));
        $this->assertCount(6, $this->rows('Background Settings Denied'));
        $this->assertSame([], $this->rows('Background Settings Changed'));
        $this->assertSame([], $this->bus->of(RmmEvent::BACKGROUND_POLICY_CHANGED));
        $this->assertSame(403, $svc->setPolicyMode(new RmmPrincipal(999, 'Nobody'), 'internal')->http, 'an unknown, signed-in user has no ability');
    }

    public function testInvalidValuesAreRefusedWithoutAChangeRow(): void
    {
        $svc = $this->h->module->backgroundSettings();
        $this->assertSame(422, $svc->setPolicyMode($this->who['admin'], 'any')->http);
        $this->assertSame(422, $svc->setInternalGroup($this->who['admin'], "a'; DROP TABLE x")->http);
        $this->assertSame(422, $svc->setClientPolicy($this->who['admin'], 0, 'allow')->http);
        $this->assertSame(422, $svc->setClientPolicy($this->who['admin'], 5, 'sometimes')->http);
        $this->assertSame([], $this->rows('Background Settings'));
        $this->assertSame('', $this->h->module->backgroundConfig()->policyMode());
    }

    public function testAFailingAuditWriteChangesNothing(): void
    {
        $failing = new class () extends InMemoryRmmAudit {
            public function record(string $action, string $description, int $clientId, int $entityId): void
            {
                throw new \RuntimeException('audit store is down');
            }
        };
        $cfg = new DbBackgroundModeConfig($this->h->module->sql());
        $svc = new BackgroundSettingsService($this->h->module->authorizer(), $cfg, $failing);
        foreach ([fn () => $svc->setPolicyMode($this->who['admin'], 'internal'), fn () => $svc->setInternalGroup($this->who['admin'], 'Ops'), fn () => $svc->setClientPolicy($this->who['admin'], 3, 'allow')] as $call) {
            try {
                $call();
                $this->fail('the audit failure must propagate');
            } catch (\RuntimeException $e) {
                $this->assertSame('audit store is down', $e->getMessage());
            }
        }
        $this->assertSame('', $cfg->policyMode());
        $this->assertSame('', $cfg->internalGroup());
        $this->assertNull($cfg->clientPolicy(3));
    }

    public function testThePolicyChangeEventCarriesOldAndNewAndNoSecret(): void
    {
        $svc = $this->h->module->backgroundSettings();
        $svc->setInternalGroup($this->who['admin'], 'Ops');
        $svc->setClientPolicy($this->who['admin'], $this->h->clientA, 'allow');
        $e = $this->bus->of(RmmEvent::BACKGROUND_POLICY_CHANGED);
        $this->assertCount(2, $e);
        $this->assertSame(['internal_group', null, 'Ops', 1, 0], [$e[0]['setting'], $e[0]['old_value'], $e[0]['new_value'], $e[0]['changed_by'], $e[0]['device_id']]);
        $this->assertSame(['client_policy', $this->h->clientA], [$e[1]['setting'], $e[1]['client_id']]);
    }

    // ------------------------------------------------------------------ b) events

    public function testSessionEventsAreOnePerOutcomeAndCarryNoSecretUrlOrToken(): void
    {
        $this->launchSetup();
        [$c, $j] = $this->h->tech('POST', [(string) $this->dev, 'remote'], $this->who['bgtech'], ['mode' => 'background', 'view' => 'console']);
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('POST', ['background', 'session-check'], $this->who['bgtech'], ['token' => $j['check_token']]);
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('POST', ['background', 'session-check'], $this->who['bgtech'], ['token' => $j['check_token']]);
        $this->assertSame(403, $c, 'replay');
        [$c] = $this->h->tech('POST', ['background', 'session-end'], $this->who['bgtech'], ['session_id' => $j['session_id'], 'reason' => 'revoked']);
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('POST', [(string) $this->dev, 'remote'], $this->who['nobg'], ['mode' => 'background', 'view' => 'console']);
        $this->assertSame(403, $c);

        $names = array_map(static fn (array $e): string => $e['event'], array_filter($this->bus->published(), static fn (array $e): bool => str_starts_with($e['event'], 'rmm.background.') && $e['event'] !== RmmEvent::BACKGROUND_POLICY_CHANGED));
        $this->assertSame([RmmEvent::BACKGROUND_SESSION_STARTED, RmmEvent::BACKGROUND_SESSION_VERIFIED, RmmEvent::BACKGROUND_SESSION_DENIED, RmmEvent::BACKGROUND_SESSION_ENDED, RmmEvent::BACKGROUND_SESSION_DENIED], array_values($names));
        $ended = $this->bus->of(RmmEvent::BACKGROUND_SESSION_ENDED)[0];
        $this->assertSame([$j['session_id'], 'revoked', 17], [$ended['session_id'], $ended['reason'], $ended['reported_by']]);
        $this->assertSame($this->dev, $this->bus->of(RmmEvent::BACKGROUND_SESSION_STARTED)[0]['device_id']);

        $all = json_encode(array_values(array_filter($this->bus->published(), static fn (array $e): bool => str_starts_with($e['event'], 'rmm.background.'))), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $j['check_token'], $all);
        $this->assertStringNotContainsString(hash('sha256', (string) $j['check_token']), $all);
        $this->assertStringNotContainsString((string) $j['url'], $all);
        $this->assertStringNotContainsString('login=', $all);
        $this->assertStringNotContainsString('http', $all);
        foreach ($this->bus->published() as $e) {
            if (str_starts_with($e['event'], 'rmm.background.')) {
                foreach (array_keys($e['payload']) as $k) {
                    $this->assertDoesNotMatchRegularExpression('/token|secret|url|key|password|cookie/i', (string) $k, $e['event'] . " field $k");
                }
            }
        }
    }

    // ------------------------------------------------------------------ c) retention

    private function insertSession(string $id, ?string $endedAgo, string $reason = ''): void
    {
        $sql = $this->h->module->sql();
        $sql->run('INSERT INTO rmm_background_sessions (session_id, device_id, client_id, asset_id, user_id, user_name, policy, notice, started_at, ended_at, end_reason) VALUES (?, 1, 1, 0, 17, ?, ?, ?, ?, ?, ?)',
            [$id, 'BgTech', 'internal', 'none', $sql->utcAt(-9999 * 86400), $endedAgo === null ? null : $sql->utcAt(-(int) $endedAgo * 86400), $reason]);
        $sql->run('INSERT INTO rmm_background_tokens (token_hash, session_id, user_id, device_id, client_id, created_at, expires_at) VALUES (?, ?, 17, 1, 1, ?, ?)', [hash('sha256', $id), $id, $sql->utcNow(), $sql->utcNow()]);
    }

    public function testRetentionPrunesOnlyOldEndedSessionsAndNeverTheAudit(): void
    {
        $sql = $this->h->module->sql();
        $this->insertSession('aaaaaaaaaaaaaaa1', '400', 'user_closed');     // ended long ago: pruned
        $this->insertSession('aaaaaaaaaaaaaaa2', '400', 'start_failed');    // closed (failed start): pruned
        $this->insertSession('aaaaaaaaaaaaaaa3', '10', 'user_closed');      // ended recently: kept
        $this->insertSession('aaaaaaaaaaaaaaa4', null);                     // active, however old: kept
        $this->h->audit->record('Background Session Ended', 'audit row of an old session', 1, 0);
        $before = count($this->h->audit->records());

        $this->assertSame(365, $this->h->module->remoteSettings()->limits()['background_session_retention_days'], 'default one year');
        $r = (new BackgroundSessionRetention($sql))->prune(365);
        $this->assertSame(['sessions' => 2, 'tokens' => 2], $r);
        $left = array_column($sql->all('SELECT session_id FROM rmm_background_sessions ORDER BY session_id'), 'session_id');
        $this->assertSame(['aaaaaaaaaaaaaaa3', 'aaaaaaaaaaaaaaa4'], $left);
        $this->assertSame(2, (int) $sql->val('SELECT COUNT(*) FROM rmm_background_tokens'));
        $this->assertCount($before, $this->h->audit->records(), 'the audit log is not touched');
        $this->assertSame(['sessions' => 0, 'tokens' => 0], (new BackgroundSessionRetention($sql))->prune(365), 'idempotent');
        $this->assertSame(['sessions' => 1, 'tokens' => 1], (new BackgroundSessionRetention($sql))->prune(5), 'a shorter retention reaches the recent one, still not the active one');
        $this->assertSame(1, (int) $sql->val('SELECT COUNT(*) FROM rmm_background_sessions WHERE ended_at IS NULL'));
    }

    public function testTheHousekeepingRunPrunesThem(): void
    {
        $this->h->enable();
        $sql = $this->h->module->sql();
        $this->insertSession('bbbbbbbbbbbbbbb1', '400', 'timeout');
        $this->insertSession('bbbbbbbbbbbbbbb2', null);
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $out['pruned_background_sessions'] ?? null);
        $this->assertSame(1, $out['pruned_background_tokens'] ?? null);
        $this->assertSame(['bbbbbbbbbbbbbbb2'], array_column($sql->all('SELECT session_id FROM rmm_background_sessions'), 'session_id'));
    }
}
