<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Migration\Migration0050ClientRemotePolicy;
use RivetCore\Rmm\Remote\DbRemoteSessionPolicyStore;
use RivetCore\Rmm\Remote\RemoteSessionPolicySettingsService;
use RivetCore\Testing\InMemoryRmmAudit;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\RoleMatrixPolicy;

/** The per-client remote-session policy on the scratch database: audited, admin-only changes that fail closed, and the stored table read back through the policy. */
final class RemoteSessionPolicyTest extends RmmTestCase
{
    /** @var array<string,RmmPrincipal> */
    private array $who = [];

    protected function makeHarness(): RmmHarness
    {
        $p = new RoleMatrixPolicy();
        $p->addUser(1, 3, 3, 1, admin: true);
        $p->addUser(18, 3, 3, 2);
        $this->who = ['admin' => new RmmPrincipal(1, 'Admin'), 'tech' => new RmmPrincipal(18, 'Tess')];

        return new RmmHarness(policy: $p);
    }

    /** @return list<array{action:string,description:string,client_id:int,entity_id:int}> */
    private function rows(string $prefix): array
    {
        return array_values(array_filter($this->h->audit->records(), static fn (array $r): bool => str_starts_with($r['action'], $prefix)));
    }

    public function testAnAdministratorChangeIsAuditedWithOldAndNewValueAndWho(): void
    {
        $svc = $this->h->module->remoteSessionPolicySettings();
        $this->assertTrue($svc->setClientPolicy($this->who['admin'], $this->h->clientA, 'notify')->ok);
        $this->assertTrue($svc->setClientPolicy($this->who['admin'], $this->h->clientA, 'approve')->ok);
        $this->assertTrue($svc->setClientPolicy($this->who['admin'], $this->h->clientA, null)->ok);
        $this->assertNull($this->h->module->remoteSessionPolicyStore()->clientPolicy($this->h->clientA));
        $d = array_column($this->rows('Remote Session Policy Changed'), 'description');
        $this->assertCount(3, $d);
        $this->assertStringContainsString("user 1 (Admin) changed the remote session policy for client {$this->h->clientA}: 'none' -> 'notify'", $d[0]);
        $this->assertStringContainsString("'notify' -> 'approve'", $d[1]);
        $this->assertStringContainsString("'approve' -> 'none'", $d[2]);
    }

    public function testNonAdministratorsChangeNothing(): void
    {
        $svc = $this->h->module->remoteSessionPolicySettings();
        $this->assertSame(403, $svc->setClientPolicy($this->who['tech'], $this->h->clientA, 'allow')->http);
        $this->assertSame(403, $svc->setClientPolicy(new RmmPrincipal(999, 'Nobody'), $this->h->clientA, 'allow')->http, 'an unknown, signed-in user has no ability');
        $this->assertNull($this->h->module->remoteSessionPolicyStore()->clientPolicy($this->h->clientA));
        $this->assertCount(2, $this->rows('Remote Session Policy Denied'));
        $this->assertSame([], $this->rows('Remote Session Policy Changed'));
    }

    public function testInvalidValuesAreRefusedWithoutAChangeRow(): void
    {
        $svc = $this->h->module->remoteSessionPolicySettings();
        $this->assertSame(422, $svc->setClientPolicy($this->who['admin'], 0, 'allow')->http);
        $this->assertSame(422, $svc->setClientPolicy($this->who['admin'], 5, 'sometimes')->http);
        $this->assertSame(403, $svc->setClientPolicy($this->who['tech'], 5, 'sometimes')->http, 'an invalid value from a non-administrator is a denial, not a validation hint');
        $this->assertSame([], $this->rows('Remote Session Policy Changed'));
    }

    public function testAFailingAuditWriteChangesNothing(): void
    {
        $failing = new class () extends InMemoryRmmAudit {
            public function record(string $action, string $description, int $clientId, int $entityId): void
            {
                throw new \RuntimeException('audit store is down');
            }
        };
        $store = new DbRemoteSessionPolicyStore($this->h->module->sql());
        $svc = new RemoteSessionPolicySettingsService($this->h->module->authorizer(), $store, $failing);
        try {
            $svc->setClientPolicy($this->who['admin'], 3, 'allow');
            $this->fail('the audit failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit store is down', $e->getMessage());
        }
        $this->assertNull($store->clientPolicy(3));
    }

    public function testThePolicyThroughTheDatabaseTable(): void
    {
        $store = new DbRemoteSessionPolicyStore($this->h->module->sql());
        $policy = $this->h->module->remoteSessionPolicy();
        $this->assertSame('no_policy', $policy->decide($this->h->clientA)->code, 'nothing stored: no extra requirement');
        $this->assertTrue($store->setClientPolicy($this->h->clientA, 'notify', 1));
        $d = $policy->decide($this->h->clientA);
        $this->assertSame([true, 'notify'], [$d->allowed, $d->notice]);
        $this->assertTrue($store->setClientPolicy($this->h->clientA, 'approve', 1));
        $d = $policy->decide($this->h->clientA);
        $this->assertSame([false, 'approval_required'], [$d->allowed, $d->code]);
        $this->h->module->sql()->run('UPDATE rmm_client_remote_policy SET policy = ? WHERE client_id = ?', ['bogus', $this->h->clientA]);
        $this->assertFalse($policy->decide($this->h->clientA)->allowed, 'a stored value nobody understands denies');
    }

    public function testMigration0050RunsAgainWithoutError(): void
    {
        $store = new DbRemoteSessionPolicyStore($this->h->module->sql());
        $this->assertTrue($store->setClientPolicy($this->h->clientA, 'notify', 1));
        $db = $this->h->module->sql()->database();
        (new Migration0050ClientRemotePolicy())->up($db);
        (new Migration0050ClientRemotePolicy())->up($db);
        $this->assertSame('notify', $store->clientPolicy($this->h->clientA), 'a second run keeps the stored policy');
    }
}
