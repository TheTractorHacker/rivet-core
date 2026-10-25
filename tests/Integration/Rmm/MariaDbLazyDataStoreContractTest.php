<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Store\RmmDataStoreFactory;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Store\RmmDataStores;
use RivetCore\Rmm\Support\DatabaseMetricSink;

/**
 * The same contract through {@see RmmDataStores::lazy()}, the object the module hands to its services: every method of every lazy delegate reaches the real
 * store unchanged, and the real stores are built once, on first use.
 */
final class MariaDbLazyDataStoreContractTest extends MariaDbDataStoreContractTest
{
    private int $resolved = 0;

    protected function stores(): RmmDataStores
    {
        if ($this->stores === null) {
            $metrics = new DatabaseMetricSink($this->h->db, $this->h->clock);
            $real = null;
            $this->stores = RmmDataStores::lazy(function () use (&$real, $metrics): RmmDataStores {
                ++$this->resolved;

                return $real ??= RmmDataStoreFactory::create(RmmDataStoreKind::MARIADB, $this->h->module->sql(), $metrics);
            }, $metrics);
            $this->assertSame(0, $this->resolved, 'building the lazy stores resolves nothing');
        }

        return $this->stores;
    }
}
