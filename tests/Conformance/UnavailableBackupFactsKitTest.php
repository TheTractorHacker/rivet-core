<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Compliance\Backup\UnavailableBackupFactsProvider;
use RivetCore\Testing\BackupFactsProviderConformanceTestCase;

/** The provider an edition without backup reporting uses must itself conform. */
final class UnavailableBackupFactsKitTest extends BackupFactsProviderConformanceTestCase
{
    protected function provider(): BackupFactsProviderInterface
    {
        return new UnavailableBackupFactsProvider();
    }
}
