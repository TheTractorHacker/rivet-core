<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Testing\BackupFactsProviderConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\InMemoryBackupFacts;

/** The same kit against an installation where no backup has ever succeeded. */
final class BackupFactsNoBackupKitTest extends BackupFactsProviderConformanceTestCase
{
    protected function provider(): BackupFactsProviderInterface
    {
        return new InMemoryBackupFacts(null, false);
    }
}
