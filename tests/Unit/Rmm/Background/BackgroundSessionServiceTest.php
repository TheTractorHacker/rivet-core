<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Background;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Remote\BackgroundModePolicy;
use RivetCore\Rmm\Remote\BackgroundSessionService;
use RivetCore\Testing\InMemoryRmmAudit;
use RivetCore\Testing\InMemoryRmmBridge;
use RivetCore\Testing\InMemoryRmmTenancy;
use RivetCore\Tests\Support\InMemoryBackgroundSessionStore;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\RoleMatrixPolicy;
use RivetCore\Tests\Support\StubBackgroundConfig;

/**
 * A1, A3, A4, A5 without a database: the gate (switch + ability + policy), exactly one audit row per outcome, and the single-use session check, over the role matrix
 * policy, the in-memory tenancy, bridge, audit and session store.
 */
final class BackgroundSessionServiceTest extends TestCase
{
    private const SECRET_URL = 'https://mesh.example/?login=SUPERSECRETLOGINCOOKIE';
    private const SESSION = '0123456789abcdef';

    private const ADMIN = 1;
    private const BG_TECH = 30;
    private const TECH_NO_BG = 31;
    private const READ_ONLY = 32;
    private const NON_TECH = 33;
    private const BG_NO_MANAGE = 34;
    private const GROUP_MEMBER_NO_ABILITY = 35;
    private const GROUP_MEMBER = 36;
    private const MODULE_ONLY = 37;

    private RoleMatrixPolicy $roles;
    private InMemoryRmmTenancy $tenancy;
    private int $clientA = 0;
    private int $clientB = 0;
    private InMemoryRmmAudit $audit;
    private InMemoryRmmBridge $bridge;
    private InMemoryBackgroundSessionStore $store;
    private MutableClock $clock;
    private StubBackgroundConfig $config;
    private BackgroundSessionService $svc;
    /** @var array<string,bool> */
    private array $features = [];
    private bool $moduleOn = true;
    private int $meshCalls = 0;
    /** @var array<string,mixed> */
    private array $meshAnswer = [];

    protected function setUp(): void
    {
        $this->roles = new RoleMatrixPolicy();
        $this->roles->addUser(self::ADMIN, 3, 3, 1, admin: true);
        $this->roles->addUser(self::BG_TECH, 3, 3, 2, background: true);
        $this->roles->addUser(self::TECH_NO_BG, 3, 3, 2);
        $this->roles->addUser(self::READ_ONLY, 1, 0, 0);
        $this->roles->addUser(self::NON_TECH, 0, 0, 0);
        $this->roles->addUser(self::BG_NO_MANAGE, 3, 3, 1, background: true);
        $this->roles->addUser(self::GROUP_MEMBER_NO_ABILITY, 3, 3, 2);
        $this->roles->addUser(self::GROUP_MEMBER, 3, 3, 2, background: true);
        $this->roles->addUser(self::MODULE_ONLY, 3, 3, 2, limited: true, background: true);
        $this->tenancy = new InMemoryRmmTenancy();
        $this->clientA = $this->tenancy->addClient('A');
        $this->clientB = $this->tenancy->addClient('B');
        $this->audit = new InMemoryRmmAudit();
        $this->clock = new MutableClock();
        $this->bridge = new InMemoryRmmBridge($this->clock);
        $this->store = new InMemoryBackgroundSessionStore($this->clock);
        $this->config = new StubBackgroundConfig('internal', 'Remote Admins', [$this->clientA => 'notify'], ['Remote Admins' => [self::GROUP_MEMBER, self::GROUP_MEMBER_NO_ABILITY]]);
        $this->features = ['background_mode' => true, 'remote_tools' => true];
        $this->moduleOn = true;
        $this->meshCalls = 0;
        $this->meshAnswer = ['ok' => true, 'code' => 'ok', 'message' => 'Opening the remote session.', 'url' => self::SECRET_URL, 'session_id' => self::SESSION];
        $this->svc = $this->service($this->audit);
    }

    private function service(RmmAuditInterface $audit): BackgroundSessionService
    {
        $authz = new RmmAuthorizer($this->roles, $this->tenancy, fn (): bool => $this->moduleOn, memoize: false);

        return new BackgroundSessionService($authz, new BackgroundModePolicy($this->config, $this->config), $this->store, $audit, $this->bridge, fn (string $f): bool => $this->features[$f] ?? false);
    }

    /** @return array<string,mixed> */
    private function device(int $client = 0): array
    {
        return ['device_id' => 77, 'client_id' => $client === 0 ? $this->clientA : $client, 'asset_id' => 500];
    }

    private function launch(int $user, string $name = 'Tess Tech', ?int $client = null): \RivetCore\Rmm\Technician\ActionResult
    {
        return $this->svc->launch(new RmmPrincipal($user, $name), $this->device($client ?? 0), function (): array {
            ++$this->meshCalls;

            return $this->meshAnswer;
        }, '203.0.113.5', 'UA');
    }

    /** @return list<string> */
    private function actions(): array
    {
        return array_column($this->audit->records(), 'action');
    }

    // ------------------------------------------------------------------ A1: gate

    public function testTheSwitchIsOffByDefaultAndEverythingIsDeniedWithoutIt(): void
    {
        $this->features = [];
        foreach ([self::ADMIN, self::BG_TECH] as $u) {
            $r = $this->launch($u);
            $this->assertFalse($r->ok);
            $this->assertSame(403, $r->http);
            $this->assertSame('feature_disabled', $r->code);
        }
        $this->assertSame(0, $this->meshCalls);
        $this->assertSame([], $this->store->sessions);
        $this->assertSame(['Background Session Denied', 'Background Session Denied'], $this->actions());
    }

    public function testAThrowingFeatureLookupCountsAsOff(): void
    {
        $authz = new RmmAuthorizer($this->roles, $this->tenancy, fn (): bool => true, memoize: false);
        $svc = new BackgroundSessionService($authz, new BackgroundModePolicy($this->config, $this->config), $this->store, $this->audit, $this->bridge, function (string $f): bool {
            throw new \RuntimeException('db down');
        });
        $this->assertFalse($svc->gate(self::ADMIN, $this->clientA)['allowed']);
    }

    public function testTheAbilityIsKnownAndInTheListAndUnknownAbilitiesAreDenied(): void
    {
        $this->assertSame('rmm.remote.background', RmmAbility::REMOTE_BACKGROUND);
        $this->assertContains(RmmAbility::REMOTE_BACKGROUND, RmmAbility::all());
        $this->assertFalse(RmmAbility::isAdministrative(RmmAbility::REMOTE_BACKGROUND));
        $this->assertNotSame('Unknown action.', RmmAbility::denial(RmmAbility::REMOTE_BACKGROUND));
        $authz = new RmmAuthorizer($this->roles, $this->tenancy, fn (): bool => true, memoize: false);
        $this->assertNotNull($authz->check(self::ADMIN, 'rmm.remote.background.anything', $this->clientA), 'an ability Core does not know is denied, even for an administrator');
        $this->assertNotNull($authz->check(self::BG_TECH, 'rmm.remote.backgroundx', $this->clientA));
    }

    /** @return array<string,array{0:int,1:bool}> user => allowed (internal mode, group "Remote Admins" holds 35 and 36) */
    public static function roleMatrix(): array
    {
        return [
            'site admin (ability by role_is_admin)' => [self::ADMIN, true],
            'technician with the ability, group member' => [self::GROUP_MEMBER, true],
            'technician with the ability but not admin or group member' => [self::BG_TECH, false],
            'technician in the group but without the ability' => [self::GROUP_MEMBER_NO_ABILITY, false],
            'technician with remote.manage but no background grant' => [self::TECH_NO_BG, false],
            'ability without remote.manage' => [self::BG_NO_MANAGE, false],
            'read-only user' => [self::READ_ONLY, false],
            'signed-in user with no RMM role' => [self::NON_TECH, false],
            'module-only login' => [self::MODULE_ONLY, false],
            'unknown user' => [999, false],
        ];
    }

    #[DataProvider('roleMatrix')]
    public function testRoleMatrixInInternalMode(int $user, bool $allowed): void
    {
        $r = $this->launch($user);
        $this->assertSame($allowed, $r->ok, $r->code . ': ' . $r->message);
        $this->assertSame($allowed ? 200 : 403, $r->http);
        $this->assertCount(1, $this->audit->records(), 'one audit row per attempt');
        $this->assertSame($allowed ? 'Background Session Started' : 'Background Session Denied', $this->actions()[0]);
    }

    public function testBothTheAbilityAndThePolicyAreNeeded(): void
    {
        // policy allows (group member) but the role does not grant the ability -> denied; ability granted but policy says no -> denied
        $this->assertFalse($this->launch(self::GROUP_MEMBER_NO_ABILITY)->ok);
        $this->assertFalse($this->launch(self::BG_TECH)->ok);
        $this->assertTrue($this->launch(self::GROUP_MEMBER)->ok);
    }

    public function testRemoteToolsMustBeOnBecauseTheConsoleHasATerminalAndFiles(): void
    {
        $this->features['remote_tools'] = false;
        $r = $this->launch(self::ADMIN);
        $this->assertSame('feature_disabled', $r->code);
        $this->assertSame(403, $r->http);
    }

    public function testModuleOffAndClientScopeDeny(): void
    {
        $this->moduleOn = false;
        $this->assertFalse($this->launch(self::ADMIN)->ok);
        $this->moduleOn = true;
        $this->tenancy->restrictUser(self::GROUP_MEMBER, [$this->clientB]);
        $r = $this->launch(self::GROUP_MEMBER);
        $this->assertFalse($r->ok);
        $this->assertSame(403, $r->http);
        $this->assertSame(0, $this->meshCalls);
    }

    // ------------------------------------------------------------------ A3 through the gate

    public function testPerClientModeAllowsNotifiesAndDenies(): void
    {
        $this->config->mode = 'per_client';
        $this->config->clientPolicies = [$this->clientA => 'notify', $this->clientB => 'approve'];
        $ok = $this->launch(self::GROUP_MEMBER, 'Gina', $this->clientA);
        $this->assertTrue($ok->ok);
        $this->assertSame('notify', $ok->data['notice'], 'the console is told to show the end-user notice');
        $approve = $this->launch(self::GROUP_MEMBER, 'Gina', $this->clientB);
        $this->assertFalse($approve->ok);
        $this->assertSame('approval_required', $approve->code);
        $this->config->clientPolicies = [];
        $none = $this->launch(self::GROUP_MEMBER, 'Gina', $this->clientA);
        $this->assertSame('forbidden', $none->code, 'a client with no policy is denied');
        $this->assertSame(['Background Session Started', 'Background Session Denied', 'Background Session Denied'], $this->actions());
    }

    public function testNoConfigurationDeniesEvenAnAdministrator(): void
    {
        $this->config->mode = '';
        $r = $this->launch(self::ADMIN);
        $this->assertFalse($r->ok);
        $this->assertSame(403, $r->http);
        $this->config->mode = 'internal';
        $this->config->throw = true;
        $this->assertFalse($this->launch(self::ADMIN)->ok);
        $this->assertSame(0, $this->meshCalls);
    }

    // ------------------------------------------------------------------ A4: audit

    public function testStartedWritesExactlyOneRowNamingTheTechnicianAndNoSecrets(): void
    {
        $r = $this->launch(self::ADMIN, 'Alice Admin');
        $this->assertTrue($r->ok);
        $rows = $this->audit->records();
        $this->assertCount(1, $rows);
        $this->assertSame('Background Session Started', $rows[0]['action']);
        $this->assertSame($this->clientA, $rows[0]['client_id']);
        $this->assertSame(500, $rows[0]['entity_id']);
        $text = $rows[0]['description'];
        $this->assertStringContainsString('user 1 (Alice Admin)', $text, 'the technician is recorded by id and name');
        $this->assertStringContainsString('device 77', $text);
        $this->assertStringContainsString(self::SESSION, $text);
        foreach ([self::SECRET_URL, 'SUPERSECRETLOGINCOOKIE', 'login=', (string) $r->data['check_token'], hash('sha256', (string) $r->data['check_token']), 'http'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text);
        }
        $this->assertSame(self::SECRET_URL, $r->data['url']);
        $this->assertSame(self::SESSION, $r->data['session_id']);
        $this->assertSame('background', $r->data['mode']);
        $this->assertSame(BackgroundSessionService::TOKEN_TTL_S, $r->data['check_expires_in']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $r->data['check_token']);
        // the edition's remote-session log gets the same marker as a normal launch and no URL
        $this->assertCount(1, $this->bridge->sessions());
        $this->assertSame('meshcentral:session:' . self::SESSION, $this->bridge->sessions()[0]['reference']);
        $this->assertSame(1, $this->bridge->sessions()[0]['user_id']);
        $this->assertStringNotContainsString('login=', json_encode($this->bridge->sessions(), JSON_THROW_ON_ERROR));
        // the session row exists with the technician and the policy; the token is stored hashed only
        $this->assertSame('Alice Admin', $this->store->sessions[self::SESSION]['user_name']);
        $this->assertSame('internal_admin', $this->store->sessions[self::SESSION]['policy']);
        $this->assertArrayNotHasKey($r->data['check_token'], $this->store->tokens);
        $this->assertArrayHasKey(hash('sha256', (string) $r->data['check_token']), $this->store->tokens);
    }

    public function testDeniedWritesExactlyOneRowNamingTheTechnicianAndStartsNothing(): void
    {
        $r = $this->launch(self::READ_ONLY, 'Rita Reader');
        $this->assertFalse($r->ok);
        $rows = $this->audit->records();
        $this->assertCount(1, $rows);
        $this->assertSame('Background Session Denied', $rows[0]['action']);
        $this->assertStringContainsString('user 32 (Rita Reader)', $rows[0]['description']);
        $this->assertStringContainsString('device 77', $rows[0]['description']);
        $this->assertSame(0, $this->meshCalls, 'a denied launch never reaches MeshCentral');
        $this->assertSame([], $this->store->sessions);
        $this->assertSame([], $this->store->tokens);
        $this->assertSame([], $this->bridge->sessions());
    }

    public function testFailedWritesExactlyOneRowAndLeavesNoSession(): void
    {
        $this->meshAnswer = ['ok' => false, 'code' => 'device_offline', 'message' => 'The device has not checked in.'];
        $r = $this->launch(self::ADMIN, 'Alice Admin');
        $this->assertFalse($r->ok);
        $this->assertSame(409, $r->http);
        $this->assertSame('device_offline', $r->code);
        $this->assertSame(['Background Session Failed'], $this->actions());
        $this->assertStringContainsString('user 1 (Alice Admin)', $this->audit->records()[0]['description']);
        $this->assertSame([], $this->store->sessions);
        $this->assertSame([], $this->bridge->sessions());
        $this->meshAnswer = ['ok' => false, 'code' => 'mesh_unavailable', 'message' => 'x'];
        $this->assertSame(503, $this->launch(self::ADMIN)->http);
        $this->meshAnswer = ['ok' => false, 'code' => 'unmapped', 'message' => 'x'];
        $this->assertSame(404, $this->launch(self::ADMIN)->http);
    }

    public function testEveryOutcomeIsAuditedWhateverTheSwitchesSay(): void
    {
        // there is no setting that skips the audit: with every switch off the denial is still a row; with them on, success and failure are rows
        $this->features = [];
        $this->launch(self::ADMIN);
        $this->features = ['background_mode' => true, 'remote_tools' => true];
        $this->launch(self::ADMIN);
        $this->meshAnswer = ['ok' => false, 'code' => 'not_configured', 'message' => 'x'];
        $this->launch(self::ADMIN);
        $this->assertSame(['Background Session Denied', 'Background Session Started', 'Background Session Failed'], $this->actions());
    }

    public function testNoAuditRowMeansNoSessionAndNoUrl(): void
    {
        $failing = new class () implements RmmAuditInterface {
            public function record(string $action, string $description, int $clientId, int $entityId): void
            {
                throw new \RuntimeException('audit store down');
            }
        };
        $svc = $this->service($failing);
        $url = null;
        try {
            $r = $svc->launch(new RmmPrincipal(self::ADMIN, 'Alice'), $this->device(), fn (): array => $this->meshAnswer);
            $url = $r->data['url'] ?? null;
            $this->fail('the launch must not succeed without its audit row');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit store down', $e->getMessage());
        }
        $this->assertNull($url);
        $this->assertTrue($this->store->sessions[self::SESSION]['ended'], 'the half-created session was closed');
        $this->assertSame('start_failed', $this->store->sessions[self::SESSION]['end_reason']);
        $this->assertSame([], $this->bridge->sessions(), 'nothing was logged to the edition as a started session');
    }

    public function testAUserNameCannotForgeALogLine(): void
    {
        $this->launch(self::READ_ONLY, "Eve\nBackground Session Started: user 1 (Admin) <script>");
        $text = $this->audit->records()[0]['description'];
        $this->assertStringNotContainsString("\n", $text);
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('user 32 (', $text);
        $this->launch(self::READ_ONLY, '');
        $this->assertStringContainsString('user 32 (unnamed)', $this->audit->records()[1]['description']);
        $this->launch(self::READ_ONLY, str_repeat('x', 500));
        $this->assertLessThan(260, strlen($this->audit->records()[2]['description']));
    }

    // ------------------------------------------------------------------ A4: ended

    public function testEndedIsRecordedOnceAndOnlyForTheOwnerOrAnAdministrator(): void
    {
        $this->launch(self::GROUP_MEMBER, 'Gina');
        $this->assertSame(404, $this->svc->end(new RmmPrincipal(self::READ_ONLY, 'Rita'), self::SESSION)->http, 'someone else cannot end it (and learns nothing)');
        $this->assertSame(404, $this->svc->end(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), 'nothex')->http);
        $this->assertSame(404, $this->svc->end(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), 'ffffffffffffffff')->http);
        $this->assertCount(1, $this->audit->records());

        $r = $this->svc->end(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), self::SESSION, 'user_closed');
        $this->assertTrue($r->ok);
        $rows = $this->audit->records();
        $this->assertCount(2, $rows);
        $this->assertSame('Background Session Ended', $rows[1]['action']);
        $this->assertStringContainsString('user 36 (Gina)', $rows[1]['description']);
        $this->assertStringContainsString('device 77', $rows[1]['description']);
        $this->assertStringContainsString('user_closed', $rows[1]['description']);
        $this->assertSame($this->clientA, $rows[1]['client_id']);

        $again = $this->svc->end(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), self::SESSION);
        $this->assertSame(409, $again->http);
        $this->assertSame('already_ended', $again->code);
        $this->assertCount(2, $this->audit->records(), 'a second report writes nothing');
    }

    public function testAnAdministratorMayEndAndAnUnknownReasonIsRecordedAsOther(): void
    {
        $this->launch(self::GROUP_MEMBER, 'Gina');
        $this->assertTrue($this->svc->end(new RmmPrincipal(self::ADMIN, 'Alice'), self::SESSION, "weird\nreason")->ok);
        $text = $this->audit->records()[1]['description'];
        $this->assertStringContainsString('(other)', $text);
        $this->assertStringContainsString('reported by user 1 (Alice)', $text);
        $this->assertSame('other', $this->store->sessions[self::SESSION]['end_reason']);
    }

    public function testASessionCanBeEndedOnTheRecordEvenWithTheSwitchOff(): void
    {
        $this->launch(self::GROUP_MEMBER, 'Gina');
        $this->features = [];
        $this->assertTrue($this->svc->end(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), self::SESSION)->ok);
        $this->assertSame(['Background Session Started', 'Background Session Ended'], $this->actions());
    }

    // ------------------------------------------------------------------ A5: session check

    private function token(int $user = self::GROUP_MEMBER, string $name = 'Gina'): string
    {
        $r = $this->launch($user, $name);
        $this->assertTrue($r->ok, $r->message);

        return (string) $r->data['check_token'];
    }

    public function testCheckAllowsOnceThenRefusesTheReplay(): void
    {
        $t = $this->token();
        $me = new RmmPrincipal(self::GROUP_MEMBER, 'Gina');
        $ok = $this->svc->check($me, $t);
        $this->assertTrue($ok->ok);
        $this->assertSame(200, $ok->http);
        $this->assertSame(self::SESSION, $ok->data['session_id']);
        $this->assertSame(77, $ok->data['device_id']);
        $this->assertSame('none', $ok->data['notice']);
        $this->assertSame(['Background Session Started', 'Background Session Verified'], $this->actions());

        $replay = $this->svc->check($me, $t);
        $this->assertFalse($replay->ok);
        $this->assertSame(403, $replay->http);
        $this->assertSame('token_used', $replay->code);
        $this->assertSame(false, $replay->data['allow']);
        $this->assertSame(['Background Session Started', 'Background Session Verified', 'Background Session Denied'], $this->actions());
        $this->assertStringContainsString('replayed', $this->audit->records()[2]['description']);
        $this->assertStringNotContainsString($t, json_encode($this->audit->records(), JSON_THROW_ON_ERROR), 'the token never reaches the audit log');
    }

    public function testCheckHonoursTheNotifyNoticeOfAPerClientPolicy(): void
    {
        $this->config->mode = 'per_client';
        $t = $this->token();
        $r = $this->svc->check(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), $t);
        $this->assertTrue($r->ok);
        $this->assertSame('notify', $r->data['notice']);
    }

    public function testTheTokenIsShortLived(): void
    {
        $t = $this->token();
        $this->clock->advance(BackgroundSessionService::TOKEN_TTL_S + 1);
        $r = $this->svc->check(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), $t);
        $this->assertFalse($r->ok);
        $this->assertSame(403, $r->http);
        $this->assertSame('token_expired', $r->code);
        $this->assertLessThanOrEqual(120, BackgroundSessionService::TOKEN_TTL_S);
        $this->assertSame('Background Session Denied', $this->actions()[1]);
    }

    public function testAnExpiredTokenIsNotUsableJustBeforeAndIsNotConsumedWhenRefusedForTheWrongCaller(): void
    {
        $t = $this->token();
        $this->clock->advance(BackgroundSessionService::TOKEN_TTL_S - 5);
        $stranger = $this->svc->check(new RmmPrincipal(self::READ_ONLY, 'Rita'), $t);
        $this->assertFalse($stranger->ok, 'another technician cannot use the token');
        $this->assertSame('forbidden', $stranger->code);
        $this->assertTrue($this->svc->check(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), $t)->ok, 'the refused stranger did not burn the owner\'s token');
    }

    public function testAnAdministratorAccountMayAskOnBehalfOfTheTechnician(): void
    {
        $t = $this->token();
        $this->assertTrue($this->svc->check(new RmmPrincipal(self::ADMIN, 'plugin'), $t)->ok);
        $this->assertStringContainsString('checked by user 1 (plugin)', $this->audit->records()[1]['description']);
    }

    public function testARevokedUserIsRefused(): void
    {
        $t = $this->token();
        $this->roles->deactivate(self::GROUP_MEMBER);
        $r = $this->svc->check(new RmmPrincipal(self::ADMIN, 'plugin'), $t);
        $this->assertFalse($r->ok);
        $this->assertSame(403, $r->http);
        $this->assertSame(false, $r->data['allow']);
        $this->assertSame('Background Session Denied', $this->actions()[1]);
        $this->assertStringContainsString('user ' . self::GROUP_MEMBER, $this->audit->records()[1]['description']);
    }

    public function testRemovingTheAbilityOrTheGroupAfterTheLaunchIsHonoured(): void
    {
        $t = $this->token();
        $this->config->members = [];
        $this->assertFalse($this->svc->check(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), $t)->ok, 'no longer in the group');
        $this->config->members = ['Remote Admins' => [self::GROUP_MEMBER]];
        $t2 = $this->token();
        $this->roles->addUser(self::GROUP_MEMBER, 3, 3, 2, background: false);
        $this->assertFalse($this->svc->check(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), $t2)->ok, 'ability withdrawn');
    }

    public function testSwitchOffAfterTheLaunchRefusesWith403(): void
    {
        $t = $this->token();
        $this->features['background_mode'] = false;
        $r = $this->svc->check(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), $t);
        $this->assertFalse($r->ok);
        $this->assertSame(403, $r->http);
        $this->assertSame('feature_disabled', $r->code);
        $this->assertSame(false, $r->data['allow']);
        $this->assertSame('Background Session Denied', $this->actions()[1]);
        $this->features['background_mode'] = true;
        $this->assertFalse($this->svc->check(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), $t)->ok, 'the refused check still used the token up');
    }

    /** @return array<string,array{0:string}> */
    public static function badTokens(): array
    {
        return ['empty' => [''], 'short' => ['abc'], 'unknown 64 hex' => [str_repeat('a', 64)], 'upper case' => [str_repeat('A', 64)], 'injection' => ["' OR 1=1 --"], 'long' => [str_repeat('f', 5000)]];
    }

    #[DataProvider('badTokens')]
    public function testUnknownOrMalformedTokensAreRefusedAndAudited(string $token): void
    {
        $this->token();   // a real session exists so a guess has something to hit
        $before = count($this->audit->records());
        $r = $this->svc->check(new RmmPrincipal(self::ADMIN, 'plugin'), $token);
        $this->assertFalse($r->ok);
        $this->assertSame(403, $r->http);
        $this->assertSame('invalid_token', $r->code);
        $this->assertCount($before + 1, $this->audit->records());
        $this->assertSame('Background Session Denied', $this->audit->records()[$before]['action']);
        $this->assertStringNotContainsString($token === '' ? "\0" : substr($token, 0, 20), $this->audit->records()[$before]['description'], 'what was submitted is not echoed into the log');
    }

    public function testAnEndedSessionCannotBeChecked(): void
    {
        $t = $this->token();
        $this->svc->end(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), self::SESSION);
        $r = $this->svc->check(new RmmPrincipal(self::GROUP_MEMBER, 'Gina'), $t);
        $this->assertFalse($r->ok);
        $this->assertSame('session_ended', $r->code);
    }

    public function testEveryCheckWritesExactlyOneRow(): void
    {
        $t = $this->token();
        $me = new RmmPrincipal(self::GROUP_MEMBER, 'Gina');
        $n = count($this->audit->records());
        foreach ([$t, $t, 'nonsense', $t] as $i => $tok) {
            $this->svc->check($me, $tok);
            $this->assertCount($n + $i + 1, $this->audit->records());
        }
    }

    public function testTheGateReportsWithoutWritingAnything(): void
    {
        $this->assertTrue($this->svc->gate(self::ADMIN, $this->clientA)['allowed']);
        $this->assertFalse($this->svc->gate(self::READ_ONLY, $this->clientA)['allowed']);
        $this->assertSame([], $this->audit->records());
    }
}
