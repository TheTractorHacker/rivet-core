<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Job\JobTypeRegistry;
use RivetCore\Testing\InMemoryRmmTenancy;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RoleMatrixPolicy;

/** The plumbing of RmmAuthorizer over a stub AccessPolicy and the in-memory tenancy: no database, no edition. */
final class AuthorizerTest extends TestCase
{
    private InMemoryRmmTenancy $tenancy;
    private int $a = 0;
    private int $b = 0;
    private bool $enabled = true;

    protected function setUp(): void
    {
        $this->tenancy = new InMemoryRmmTenancy();
        $this->a = $this->tenancy->addClient('A');
        $this->b = $this->tenancy->addClient('B');
        $this->enabled = true;
    }

    private function authz(AccessPolicyInterface $p): RmmAuthorizer
    {
        return new RmmAuthorizer($p, $this->tenancy, fn (): bool => $this->enabled);
    }

    public function testAbilityNamesAreTheDocumentedOnesAndMatchTheJobRegistry(): void
    {
        $this->assertSame(['rmm.device.view', 'rmm.device.manage', 'rmm.job.run_saved', 'rmm.job.reboot', 'rmm.job.run_script', 'rmm.remote.launch', 'rmm.token.manage', 'rmm.binary.publish', 'rmm.admin'], RmmAbility::all());
        $this->assertSame(JobTypeRegistry::ABILITY_RUN_SAVED, RmmAbility::JOB_RUN_SAVED);
        $this->assertSame(JobTypeRegistry::ABILITY_REBOOT, RmmAbility::JOB_REBOOT);
        $this->assertSame(JobTypeRegistry::ABILITY_RUN_SCRIPT, RmmAbility::JOB_RUN_SCRIPT);
        foreach (['rmm.admin', 'rmm.device.manage', 'rmm.token.manage', 'rmm.binary.publish'] as $a) {
            $this->assertTrue(RmmAbility::isAdministrative($a));
        }
        foreach (['rmm.device.view', 'rmm.job.run_saved', 'rmm.job.reboot', 'rmm.job.run_script', 'rmm.remote.launch'] as $a) {
            $this->assertFalse(RmmAbility::isAdministrative($a));
        }
        foreach (RmmAbility::all() as $a) {
            $this->assertNotSame('Unknown action.', RmmAbility::denial($a), $a);
        }
        $this->assertSame('Unknown action.', RmmAbility::denial('rmm.nope'));
    }

    public function testAllowedWhenViewAndTheAbilityAreGrantedInsideTheScope(): void
    {
        $z = $this->authz(new AllowUsersPolicy([5 => true]));
        $this->assertNull($z->check(5, RmmAbility::JOB_RUN_SCRIPT, $this->a));
        $this->assertNull($z->check(5, RmmAbility::DEVICE_VIEW, $this->a));
        $this->assertNull($z->check(5, RmmAbility::REMOTE_LAUNCH, 0), 'client 0 asks the role-level question');
        $this->assertTrue($z->allowed(5, RmmAbility::ADMIN, 0));
    }

    public function testTheRoleLevelViewQuestionComesFirst(): void
    {
        $p = new AllowUsersPolicy([5 => [RmmAbility::JOB_REBOOT]]);   // may reboot but not view
        $z = $this->authz($p);
        $this->assertSame('Your role cannot view RMM devices.', $z->check(5, RmmAbility::JOB_REBOOT, $this->a));
        $this->assertSame([[5, 'rmm.device.view', 'client', 0]], array_map(static fn (array $q): array => [$q[0], $q[1], $q[2], $q[3]], $p->asked));
    }

    public function testAnAbilityTheRoleLacksGetsTheGenericReason(): void
    {
        $z = $this->authz(new AllowUsersPolicy([5 => [RmmAbility::DEVICE_VIEW]]));
        $this->assertSame('Your role cannot run free-form PowerShell.', $z->check(5, RmmAbility::JOB_RUN_SCRIPT, $this->a));
        $this->assertSame('Your role cannot run jobs on devices.', $z->check(5, RmmAbility::JOB_RUN_SAVED, $this->a));
        $this->assertSame('Your role cannot run jobs on devices.', $z->check(5, RmmAbility::JOB_REBOOT, $this->a));
        $this->assertSame('Your role cannot open remote sessions.', $z->check(5, RmmAbility::REMOTE_LAUNCH, $this->a));
        $this->assertSame('Administrator access is required.', $z->check(5, RmmAbility::ADMIN, 0));
        $this->assertSame('Administrator access is required.', $z->check(5, RmmAbility::TOKEN_MANAGE, $this->a));
    }

    public function testModuleSwitchedOffDeniesEverythingUnlessTheOperationWorksWhileOff(): void
    {
        $z = $this->authz(new AllowUsersPolicy([5 => true]));
        $this->enabled = false;
        foreach (RmmAbility::all() as $a) {
            $this->assertSame('The endpoint agent is not enabled.', $z->check(5, $a, $this->a), $a);
            $this->assertNull($z->check(5, $a, $this->a, false), "$a works while off when asked to");
        }
        $this->assertFalse($z->moduleEnabled());
    }

    public function testClientScopeIsEnforcedByTheTenancyAndAdministratorsStillHonourItForDeviceAbilities(): void
    {
        $this->tenancy->restrictUser(5, [$this->b]);
        $z = $this->authz(new AllowUsersPolicy([5 => true]));
        $this->assertSame(RmmAuthorizer::NO_CLIENT_ACCESS, $z->check(5, RmmAbility::DEVICE_VIEW, $this->a));
        $this->assertSame(RmmAuthorizer::NO_CLIENT_ACCESS, $z->check(5, RmmAbility::JOB_RUN_SCRIPT, $this->a));
        $this->assertSame(RmmAuthorizer::NO_CLIENT_ACCESS, $z->check(5, RmmAbility::DEVICE_MANAGE, $this->a));
        $this->assertNull($z->check(5, RmmAbility::JOB_RUN_SCRIPT, $this->b));
        $this->assertNull($z->check(5, RmmAbility::JOB_RUN_SCRIPT, 0), 'client 0 is inside every scope');
        $this->assertNull($z->check(5, RmmAbility::ADMIN, $this->a), 'module-wide settings are not client scoped');
        $this->assertSame([$this->b], $z->visibleClientIds(5));
        $this->tenancy->restrictUser(5, []);
        $this->assertFalse($z->clientOk(5, $this->b));
        $this->assertTrue($z->clientOk(5, 0));
        $this->assertNull($z->visibleClientIds(6));
    }

    public function testAnonymousInactiveAndUnknownCallersAreDenied(): void
    {
        $z = $this->authz(new AllowUsersPolicy([5 => true]));
        $this->assertSame(RmmAuthorizer::NOT_ACTIVE, $z->check(0, RmmAbility::DEVICE_VIEW, 0));
        $this->assertSame(RmmAuthorizer::NOT_ACTIVE, $z->check(-3, RmmAbility::DEVICE_VIEW, 0));
        $this->assertNotNull($z->check(99, RmmAbility::DEVICE_VIEW, 0), 'a user the policy does not know');
        $this->assertSame('Unknown action.', $z->check(5, 'rmm.format_everything', 0), 'an ability Core does not define is never asked of the policy');
    }

    public function testAPolicyThatThrowsDeniesInsteadOfPassing(): void
    {
        $z = $this->authz(new class implements AccessPolicyInterface {
            public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool
            {
                throw new \RuntimeException('policy exploded');
            }
        });
        $this->assertNotNull($z->check(5, RmmAbility::DEVICE_VIEW, 0));
        $this->assertNotNull($z->check(5, RmmAbility::ADMIN, 0));
    }

    public function testTheRoleMatrixPolicyModelsRivetItsRules(): void
    {
        $p = new RoleMatrixPolicy();
        $p->addUser(1, admin: true);
        $p->addUser(2, 3, 3, 1);
        $p->addUser(3, 1, 2, 0);
        $p->addUser(4, 3, 3, 1, limited: true);
        $z = $this->authz($p);
        $this->assertNull($z->check(1, RmmAbility::ADMIN, 0));
        $this->assertNull($z->check(2, RmmAbility::JOB_RUN_SCRIPT, $this->a));
        $this->assertNotNull($z->check(2, RmmAbility::ADMIN, 0));
        $this->assertNull($z->check(3, RmmAbility::JOB_REBOOT, $this->a));
        $this->assertNotNull($z->check(3, RmmAbility::JOB_RUN_SCRIPT, $this->a));
        $this->assertNull($z->check(4, RmmAbility::DEVICE_VIEW, $this->a), 'a module-only login may view');
        $this->assertNotNull($z->check(4, RmmAbility::JOB_RUN_SAVED, $this->a), 'but never run');
        $this->assertNotNull($z->check(4, RmmAbility::REMOTE_LAUNCH, $this->a), 'or open a remote session');
        $p->deactivate(2);
        $this->assertNotNull($z->check(2, RmmAbility::DEVICE_VIEW, 0), 'an inactive account is denied');
    }

    public function testTerminologyAndPerAbilityDenialTexts(): void
    {
        $policy = new AllowUsersPolicy([5 => true]);
        $dept = new RmmAuthorizer($policy, $this->tenancy, fn (): bool => true, 'department');
        $this->assertSame('You do not have access to this device\'s department.', $dept->noClientAccess());
        $this->assertSame(RmmAuthorizer::NO_CLIENT_ACCESS, $this->authz($policy)->noClientAccess(), 'the default terminology is the constant');

        // out-of-scope client: the configured wording, not the constant
        $this->tenancy->restrictUser(5, [$this->a]);
        $scoped = new RmmAuthorizer($policy, $this->tenancy, fn (): bool => true, 'department');
        $this->assertSame('You do not have access to this device\'s department.', $scoped->check(5, RmmAbility::DEVICE_VIEW, $this->b));

        // a role that may view but not run scripts: generic text by default, the edition's text when it gave one
        $viewOnly = new AllowUsersPolicy([5 => [RmmAbility::DEVICE_VIEW]]);
        $generic = $this->authz($viewOnly);
        $this->assertSame('Your role cannot run free-form PowerShell.', $generic->check(5, RmmAbility::JOB_RUN_SCRIPT, $this->a));
        $custom = new RmmAuthorizer($viewOnly, $this->tenancy, fn (): bool => true, 'client', [
            RmmAbility::JOB_RUN_SCRIPT => 'Ask a Level 3 technician to run this.', RmmAbility::REMOTE_LAUNCH => '   ', 'rmm.nope' => 'ignored',
        ]);
        $this->assertSame('Ask a Level 3 technician to run this.', $custom->check(5, RmmAbility::JOB_RUN_SCRIPT, $this->a));
        $this->assertSame('Ask a Level 3 technician to run this.', $custom->denial(RmmAbility::JOB_RUN_SCRIPT));
        $this->assertSame('Your role cannot open remote sessions.', $custom->check(5, RmmAbility::REMOTE_LAUNCH, $this->a), 'a blank override falls back to the generic text');
        $this->assertSame('Your role cannot run jobs on devices.', $custom->check(5, RmmAbility::JOB_REBOOT, $this->a), 'an ability without an override keeps its generic text');
    }
}
