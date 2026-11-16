<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\RemoteSession;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Remote\RemoteSessionDecision;
use RivetCore\Rmm\Remote\RemoteSessionPolicy;
use RivetCore\Tests\Support\StubRemoteSessionPolicyStore;

/** The per-client policy answers only "what is the person at this client's devices owed"; an unreadable or unknown policy denies. */
final class RemoteSessionPolicyTest extends TestCase
{
    /** @return array<string,array{0:?string,1:bool,2:string,3:string}> */
    public static function perClient(): array
    {
        return [
            'allow' => ['allow', true, 'client_allow', RemoteSessionDecision::NOTICE_NONE],
            'notify' => ['notify', true, 'client_notify', RemoteSessionDecision::NOTICE_NOTIFY],
            'approve is refused until an approval flow exists' => ['approve', false, 'approval_required', RemoteSessionDecision::NOTICE_APPROVE],
            'no row means no extra requirement' => [null, true, 'no_policy', RemoteSessionDecision::NOTICE_NONE],
            'unknown value denies' => ['always', false, 'not_configured', RemoteSessionDecision::NOTICE_NONE],
            'wrong case denies' => ['ALLOW', false, 'not_configured', RemoteSessionDecision::NOTICE_NONE],
            'empty value denies' => ['', false, 'not_configured', RemoteSessionDecision::NOTICE_NONE],
        ];
    }

    #[DataProvider('perClient')]
    public function testPerClientPolicy(?string $stored, bool $allowed, string $code, string $notice): void
    {
        $d = (new RemoteSessionPolicy(new StubRemoteSessionPolicyStore($stored === null ? [] : [7 => $stored])))->decide(7);
        $this->assertSame($allowed, $d->allowed);
        $this->assertSame($code, $d->code);
        $this->assertSame($notice, $d->notice);
    }

    public function testThePolicyIsPerClientAndAClientOfZeroHasNone(): void
    {
        $p = new RemoteSessionPolicy(new StubRemoteSessionPolicyStore([7 => 'approve', 8 => 'notify']));
        $this->assertFalse($p->decide(7)->allowed);
        $this->assertSame('client_notify', $p->decide(8)->code);
        $this->assertSame('no_policy', $p->decide(9)->code, 'another client is not affected');
        $this->assertSame('no_policy', $p->decide(0)->code, 'no client, no policy');
        $this->assertSame('no_policy', $p->decide(-1)->code);
    }

    public function testAFailingLookupDenies(): void
    {
        $s = new StubRemoteSessionPolicyStore([7 => 'allow']);
        $s->throw = true;
        $d = (new RemoteSessionPolicy($s))->decide(7);
        $this->assertFalse($d->allowed, 'a policy that cannot be read denies rather than allows');
        $this->assertSame('not_configured', $d->code);
    }

    public function testThePolicyValuesAreTheThreeDocumentedOnes(): void
    {
        $this->assertSame(['allow', 'notify', 'approve'], RemoteSessionPolicy::CLIENT_POLICIES);
    }
}
