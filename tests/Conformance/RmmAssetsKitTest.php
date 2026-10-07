<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\RmmAssetsInterface;
use RivetCore\Testing\RmmAssetsConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedRmmAssets;

/** The kit against the in-memory assets store (and the harness target for the assets mutants). */
final class RmmAssetsKitTest extends RmmAssetsConformanceTestCase
{
    use Flaw;

    private ?FlawedRmmAssets $store = null;

    private function store(): FlawedRmmAssets
    {
        return $this->store ??= new FlawedRmmAssets(self::$flaw);
    }

    protected function assets(): RmmAssetsInterface
    {
        return $this->store();
    }

    protected function createAsset(array $asset): int
    {
        return $this->store()->addAsset($asset);
    }

    protected function readAsset(int $assetId): ?array
    {
        $r = $this->store()->row($assetId);

        return $r === null ? null : [
            'name' => $r['name'], 'client_id' => $r['client_id'], 'location_id' => $r['location_id'], 'serial' => $r['serial'],
            'model' => $r['model'], 'make' => $r['make'], 'os' => $r['os'], 'type' => $r['type'], 'status' => $r['status'],
        ];
    }
}
