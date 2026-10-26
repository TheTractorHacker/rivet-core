<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\Backup\BackupFactsProviderInterface;

/** Backup facts a test sets directly. */
final class FakeBackupFacts implements BackupFactsProviderInterface
{
    public function __construct(public BackupFacts $facts)
    {
    }

    public function backupFacts(): BackupFacts
    {
        return $this->facts;
    }
}
