<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Backup;

/**
 * The provider for an edition that has not implemented {@see BackupFactsProviderInterface} yet: it says honestly that the facts are not available, so the
 * backup checks report "not available" (never a pass).
 *
 * @api
 */
final class UnavailableBackupFactsProvider implements BackupFactsProviderInterface
{
    public function backupFacts(): BackupFacts
    {
        return BackupFacts::unavailable();
    }
}
