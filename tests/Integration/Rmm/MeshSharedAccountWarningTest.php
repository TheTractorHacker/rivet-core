<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\RmmTestCase;

/** CORE-12: a shared MeshCentral account makes Core's per-technician limits meaningless inside Mesh; saving such settings warns. */
final class MeshSharedAccountWarningTest extends RmmTestCase
{
    /** @param array<string,mixed> $in */
    private function save(array $in): array
    {
        $r = $this->h->module->admin()->saveMesh($this->h->principal(), $in + ['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.test']);
        $this->assertTrue($r->ok, $r->message);

        return $r->data['warnings'] ?? [];
    }

    public function testASharedAccountNameIsWarnedAbout(): void
    {
        $w = $this->save(['mesh_account_template' => 'support']);
        $this->assertNotEmpty(array_filter($w, static fn ($x) => str_contains((string) $x, 'same Mesh account')));
    }

    public function testPerTechnicianNamesAndDisabledMeshAreNotWarnedAbout(): void
    {
        foreach (['rivet-{user_id}', '{username}'] as $tpl) {
            $this->assertSame([], array_filter($this->save(['mesh_account_template' => $tpl]), static fn ($x) => str_contains((string) $x, 'same Mesh account')), $tpl);
        }
        $this->assertSame([], $this->save(['mesh_account_template' => 'support', 'mesh_enabled' => 0, 'mesh_url' => '']));
    }
}
