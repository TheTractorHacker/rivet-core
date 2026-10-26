<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Backup;

/**
 * What an edition tells Core about its backup. Core ships the checks (backup coverage, recency, encryption, off-site copy, retention, restore test); only
 * the edition knows what its backup script or in-app backup really did, so the edition reports the facts and Core never reads the edition's internals,
 * files or secrets.
 *
 * Contract (checked by {@see \RivetCore\Testing\BackupFactsProviderConformanceTestCase}):
 *  - Read-only and repeatable: no side effects; two calls in a row give the same facts unless a backup finished in between.
 *  - Never throws. Say {@see BackupFacts::unavailable()} when the backup cannot be inspected.
 *  - Report what the NEWEST SUCCESSFUL backup did, from the edition's own record of it (a log row, a manifest, the exit status of the last run), not from
 *    its configuration: a script that is configured for off-site upload but whose upload failed has `offsite` missing from `destinations`.
 *  - With no successful backup, claim nothing else (no stores, no destinations).
 *  - No secrets: never a key, passphrase, token, bucket credential or URL with credentials in any field.
 *
 * @api
 */
interface BackupFactsProviderInterface
{
    public function backupFacts(): BackupFacts;
}
