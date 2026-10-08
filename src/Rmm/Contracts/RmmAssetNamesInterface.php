<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * OPTIONAL companion of {@see RmmAssetsInterface}: the display names of assets, in one batch, so that
 * {@see \RivetCore\Rmm\Read\RmmReadModel::listDevices()} can put `asset_name` on every summary and an edition's device list needs no
 * query of its own. An assets adapter that implements this interface is picked up by {@see \RivetCore\Rmm\RmmModule} automatically;
 * one that does not makes `asset_name` null. Existing adapters keep working unchanged.
 *
 * @api
 */
interface RmmAssetNamesInterface
{
    /**
     * @param list<int> $assetIds at most one page of devices (500)
     * @return array<int,string> asset id => name; ids that do not exist (or are not visible) are simply absent
     */
    public function assetNames(array $assetIds): array;
}
