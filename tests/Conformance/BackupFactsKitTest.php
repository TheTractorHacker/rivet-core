<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Testing\BackupFactsProviderConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\InMemoryBackupFacts;

/** The kit against an in-memory provider (and the harness target for the backup-facts mutants). */
final class BackupFactsKitTest extends BackupFactsProviderConformanceTestCase
{
    use Flaw;

    private ?InMemoryBackupFacts $provider = null;

    protected function provider(): BackupFactsProviderInterface
    {
        return $this->provider ??= new InMemoryBackupFacts(self::$flaw);
    }
}
