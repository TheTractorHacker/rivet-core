<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Compliance\Backup\RestoreTestStore;
use RivetCore\Compliance\Profile\FixedRequirementsProvider;
use RivetCore\Compliance\Profile\RequirementsProviderInterface;
use RivetCore\Contracts\ClockInterface;
use RivetCore\Rmm\Change\ChangeSettings;
use RivetCore\Rmm\Remote\RemoteSettings;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Store\RmmDataStores;

/**
 * Builds the checks that belong to the selected framework profile, ready for {@see \RivetCore\Compliance\ComplianceAssessor}:
 *
 * ```php
 * $checks = [...$editionChecks, ...(new ProfileChecks($store, $clock, $backupFacts, $restoreTests, fn () => $module->settings()->dataStore(),
 *     $module->dataStores(), $module->settings(), $module->remoteSettings(), $module->changeSettings(), fn () => $auditDays, '/admin/compliance'))->checks()];
 * ```
 *
 * {@see self::checks()} reads the selected profile once and returns only the checks whose requirements it contains, none when no profile is selected, so an
 * install that has not opted in gets exactly the checks it had before. The RMM arguments are optional: without them (an edition without the RMM module)
 * the RMM history check is left out. Backup and restore-test checks are built when the profile asks for them even if the edition has no
 * {@see BackupFactsProviderInterface} (pass {@see \RivetCore\Compliance\Backup\UnavailableBackupFactsProvider}): they then say "not available".
 *
 * @api
 */
final class ProfileChecks
{
    /**
     * @param \Closure(): string $dataStoreKind the active RMM data store (`mariadb` or `postgres`)
     * @param (\Closure(): int)|null $auditDays days the edition keeps audit events (0 = no limit); null leaves out the audit retention check
     */
    public function __construct(
        private RequirementsProviderInterface $profiles,
        private ClockInterface $clock,
        private BackupFactsProviderInterface $facts,
        private RestoreTestStore $restoreTests,
        private \Closure $dataStoreKind,
        private ?RmmDataStores $stores = null,
        private ?RmmSettings $settings = null,
        private ?RemoteSettings $remote = null,
        private ?ChangeSettings $change = null,
        private ?\Closure $auditDays = null,
        private string $settingsPath = '',
    ) {
    }

    /** @return list<\RivetCore\Compliance\CheckInterface> the checks the selected profile asks for (none without a selected profile) */
    public function checks(): array
    {
        $profile = $this->profiles->selectedProfile();
        if ($profile === null) {
            return [];
        }
        $source = new FixedRequirementsProvider($profile);   // one read for all of them

        return array_values(array_filter($this->build($source), static fn (AbstractProfileCheck $c): bool => $c->appliesTo($profile)));
    }

    /**
     * Every check, whether or not the profile asks for it (each answers "not applicable" when it does not).
     *
     * @return list<\RivetCore\Compliance\CheckInterface>
     */
    public function all(): array
    {
        return $this->build($this->profiles);
    }

    /** @return list<AbstractProfileCheck> */
    private function build(RequirementsProviderInterface $p): array
    {
        $checks = [
            new BackupCoverageCheck($p, $this->facts, $this->clock, $this->dataStoreKind, $this->settingsPath),
            new BackupRecencyCheck($p, $this->facts, $this->clock, $this->settingsPath),
            new BackupEncryptedCheck($p, $this->facts, $this->clock, $this->settingsPath),
            new BackupOffsiteCheck($p, $this->facts, $this->clock, $this->settingsPath),
            new BackupRetentionCheck($p, $this->facts, $this->clock, $this->settingsPath),
            new RestoreTestRecencyCheck($p, $this->restoreTests, $this->facts, $this->clock, $this->dataStoreKind, $this->settingsPath),
            new IntegrityChainCheck($p, $this->clock, $this->settingsPath),
        ];
        if ($this->stores !== null && $this->settings !== null && $this->remote !== null && $this->change !== null) {
            $checks[] = new DataStoreRetentionCheck($p, $this->clock, $this->stores, $this->settings, $this->remote, $this->change, $this->settingsPath);
        }
        if ($this->settings !== null && $this->remote !== null) {
            $checks[] = new SecurityLogPresetsCheck($p, $this->clock, $this->remote, $this->settings, $this->settingsPath);
        }
        if ($this->auditDays !== null) {
            $checks[] = new AuditRetentionCheck($p, $this->clock, $this->auditDays, $this->settingsPath);
        }

        return $checks;
    }
}
