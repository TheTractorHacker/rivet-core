<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Admin\RmmAdmin;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Binaries\BinaryStore;
use RivetCore\Rmm\Http\TechnicianApi;
use RivetCore\Rmm\Installer\InstallerService;
use RivetCore\Rmm\Mesh\MeshService;
use RivetCore\Rmm\Read\RmmReadModel;
use RivetCore\Rmm\RmmModule;
use RivetCore\Rmm\Technician\TechnicianActions;
use RivetCore\Testing\InMemoryRmmAssets;
use RivetCore\Testing\InMemoryRmmBridge;
use RivetCore\Testing\InMemoryRmmTenancy;
use RivetCore\Testing\InMemorySecretBox;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\FakeDatabase;
use RivetCore\Tests\Support\FixedClock;

/** The technician side of the composition root is built lazily and needs the edition's access policy. No database is touched. */
final class ModuleWiringTest extends TestCase
{
    private function module(bool $withPolicy): RmmModule
    {
        $clock = new FixedClock();

        return new RmmModule(new FakeDatabase(), $clock, new InMemoryRmmTenancy(), new InMemoryRmmAssets(), new InMemoryRmmBridge($clock), new InMemorySecretBox(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            policy: $withPolicy ? new AllowUsersPolicy([1 => true]) : null);
    }

    public function testTheTechnicianSideNeedsAPolicy(): void
    {
        $m = $this->module(false);
        foreach (['authorizer', 'technician', 'admin', 'technicianApi'] as $method) {
            try {
                $m->$method();
                $this->fail("$method() worked without an access policy");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('AccessPolicyInterface', $e->getMessage());
            }
        }
        // the parts that need no policy still build
        $this->assertInstanceOf(BinaryStore::class, $m->binaryStore());
        $this->assertInstanceOf(MeshService::class, $m->mesh());
        $this->assertInstanceOf(InstallerService::class, $m->installerService());
        $this->assertInstanceOf(RmmReadModel::class, $m->readModel());
    }

    public function testEverythingIsBuiltOnceAndSharedWithAPolicy(): void
    {
        $m = $this->module(true);
        $this->assertInstanceOf(RmmAuthorizer::class, $m->authorizer());
        $this->assertSame($m->authorizer(), $m->authorizer());
        $this->assertSame($m->technician(), $m->technician());
        $this->assertSame($m->admin(), $m->admin());
        $this->assertSame($m->readModel(), $m->readModel());
        $this->assertInstanceOf(TechnicianActions::class, $m->technician());
        $this->assertInstanceOf(RmmAdmin::class, $m->admin());
        $this->assertInstanceOf(TechnicianApi::class, $m->technicianApi());
    }
}
