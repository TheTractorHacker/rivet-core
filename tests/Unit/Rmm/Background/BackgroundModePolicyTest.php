<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Background;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Remote\BackgroundDecision;
use RivetCore\Rmm\Remote\BackgroundModePolicy;
use RivetCore\Rmm\Remote\NoBackgroundGroups;
use RivetCore\Tests\Support\StubBackgroundConfig;

/** A3: the policy answers only "may this user, for this client"; everything not explicitly allowed is denied. */
final class BackgroundModePolicyTest extends TestCase
{
    private const ADMIN = 1;
    private const GROUP_MEMBER = 20;
    private const TECH = 21;
    private const READ_ONLY = 22;
    private const SIGNED_IN_NON_TECH = 23;

    private function internal(): StubBackgroundConfig
    {
        return new StubBackgroundConfig('internal', 'Remote Admins', [], ['Remote Admins' => [self::GROUP_MEMBER]]);
    }

    private function policy(StubBackgroundConfig $c): BackgroundModePolicy
    {
        return new BackgroundModePolicy($c, $c);
    }

    /** @return array<string,array{0:int,1:bool,2:bool,3:string}> */
    public static function internalMatrix(): array
    {
        return [
            'site admin' => [self::ADMIN, true, true, 'internal_admin'],
            'named group member' => [self::GROUP_MEMBER, false, true, 'internal_group'],
            'plain technician' => [self::TECH, false, false, 'not_allowed'],
            'read-only user' => [self::READ_ONLY, false, false, 'not_allowed'],
            'signed-in non-technician' => [self::SIGNED_IN_NON_TECH, false, false, 'not_allowed'],
            'user id 0' => [0, true, false, 'not_allowed'],
            'negative user id' => [-5, true, false, 'not_allowed'],
        ];
    }

    #[DataProvider('internalMatrix')]
    public function testInternalMode(int $user, bool $admin, bool $allowed, string $code): void
    {
        $d = $this->policy($this->internal())->decide($user, 7, $admin);
        $this->assertSame($allowed, $d->allowed, $code);
        $this->assertSame($code, $d->code);
        $this->assertSame(BackgroundDecision::NOTICE_NONE, $d->notice, 'internal mode has no end-user prompt');
    }

    public function testInternalModeWithoutAGroupAllowsSiteAdminsOnly(): void
    {
        $c = $this->internal();
        $c->group = '';
        $c->members = ['' => [self::TECH]];   // even a lookup that says yes for an empty name must not be asked
        $p = $this->policy($c);
        $this->assertTrue($p->decide(self::ADMIN, 7, true)->allowed);
        $this->assertFalse($p->decide(self::TECH, 7, false)->allowed);
        $c->group = '   ';
        $this->assertFalse($p->decide(self::TECH, 7, false)->allowed, 'a blank group name grants nothing');
    }

    public function testTheDefaultGroupLookupGrantsNobody(): void
    {
        $c = $this->internal();
        $p = new BackgroundModePolicy($c, new NoBackgroundGroups());
        $this->assertFalse($p->decide(self::GROUP_MEMBER, 7, false)->allowed);
        $this->assertTrue($p->decide(self::ADMIN, 7, true)->allowed);
    }

    public function testInternalModeIgnoresThePerClientTable(): void
    {
        $c = $this->internal();
        $c->clientPolicies = [7 => 'allow'];
        $this->assertFalse($this->policy($c)->decide(self::TECH, 7, false)->allowed, 'a client allow never opens internal mode to a technician');
    }

    /** @return array<string,array{0:?string,1:bool,2:string,3:string}> */
    public static function perClient(): array
    {
        return [
            'allow' => ['allow', true, 'client_allow', BackgroundDecision::NOTICE_NONE],
            'notify' => ['notify', true, 'client_notify', BackgroundDecision::NOTICE_NOTIFY],
            'approve is refused until an approval flow exists' => ['approve', false, 'approval_required', BackgroundDecision::NOTICE_APPROVE],
            'no row' => [null, false, 'not_configured', BackgroundDecision::NOTICE_NONE],
            'unknown value' => ['always', false, 'not_configured', BackgroundDecision::NOTICE_NONE],
            'wrong case' => ['ALLOW', false, 'not_configured', BackgroundDecision::NOTICE_NONE],
            'empty value' => ['', false, 'not_configured', BackgroundDecision::NOTICE_NONE],
        ];
    }

    #[DataProvider('perClient')]
    public function testPerClientMode(?string $stored, bool $allowed, string $code, string $notice): void
    {
        $c = new StubBackgroundConfig('per_client', '', $stored === null ? [] : [7 => $stored]);
        $d = $this->policy($c)->decide(self::TECH, 7, false);
        $this->assertSame($allowed, $d->allowed);
        $this->assertSame($code, $d->code);
        $this->assertSame($notice, $d->notice);
    }

    public function testPerClientModeIsPerClientAndNeverForClientZero(): void
    {
        $c = new StubBackgroundConfig('per_client', '', [7 => 'allow', 8 => 'notify']);
        $p = $this->policy($c);
        $this->assertTrue($p->decide(self::TECH, 7, false)->allowed);
        $this->assertFalse($p->decide(self::TECH, 9, false)->allowed, 'a client with no policy is denied');
        $this->assertFalse($p->decide(self::TECH, 0, true)->allowed, 'no client, no policy, even for a site admin');
        $this->assertFalse($p->decide(self::TECH, -1, false)->allowed);
    }

    public function testPerClientModeDoesNotPromoteAdminsOrGroupMembers(): void
    {
        $c = new StubBackgroundConfig('per_client', 'Remote Admins', [], ['Remote Admins' => [self::GROUP_MEMBER]]);
        $p = $this->policy($c);
        $this->assertFalse($p->decide(self::ADMIN, 7, true)->allowed, 'MSP mode: the client decides, not the role');
        $this->assertFalse($p->decide(self::GROUP_MEMBER, 7, false)->allowed);
    }

    /** @return array<string,array{0:string}> */
    public static function badModes(): array
    {
        return ['empty (not configured)' => [''], 'unknown' => ['open'], 'wrong case' => ['Internal'], 'any' => ['any'], 'padded' => [' internal'], 'sql' => ["internal' OR '1'='1"]];
    }

    #[DataProvider('badModes')]
    public function testMissingOrUnknownConfigurationDeniesEveryone(string $mode): void
    {
        $c = new StubBackgroundConfig($mode, 'Remote Admins', [7 => 'allow'], ['Remote Admins' => [self::GROUP_MEMBER]]);
        $p = $this->policy($c);
        foreach ([[self::ADMIN, true], [self::GROUP_MEMBER, false], [self::TECH, false]] as [$u, $admin]) {
            $d = $p->decide($u, 7, $admin);
            $this->assertFalse($d->allowed, "user $u, mode '$mode'");
            $this->assertSame('not_configured', $d->code);
        }
    }

    public function testAFailingConfigurationOrLookupDenies(): void
    {
        $c = $this->internal();
        $c->throw = true;
        $d = $this->policy($c)->decide(self::ADMIN, 7, true);
        $this->assertFalse($d->allowed, 'even a site admin is denied when the configuration cannot be read');
        $this->assertSame('not_configured', $d->code);

        // the mode reads fine but the group lookup throws: the member is denied, the admin (decided before the lookup) is not affected
        $partial = new class () implements \RivetCore\Rmm\Contracts\BackgroundGroupMembershipInterface {
            public function isMember(int $userId, string $group): bool
            {
                throw new \RuntimeException('directory down');
            }
        };
        $p = new BackgroundModePolicy($this->internal(), $partial);
        $this->assertFalse($p->decide(self::GROUP_MEMBER, 7, false)->allowed);
        $this->assertTrue($p->decide(self::ADMIN, 7, true)->allowed);
    }

    public function testThereIsNoModeThatAllowsAnySignedInUser(): void
    {
        $this->assertSame(['internal', 'per_client'], BackgroundModePolicy::MODES);
        $this->assertSame(['allow', 'notify', 'approve'], BackgroundModePolicy::CLIENT_POLICIES);
        foreach (BackgroundModePolicy::MODES as $mode) {
            $c = new StubBackgroundConfig($mode);   // nothing else configured
            $this->assertFalse($this->policy($c)->decide(self::SIGNED_IN_NON_TECH, 7, false)->allowed, $mode);
        }
    }
}
