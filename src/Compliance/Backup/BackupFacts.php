<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Backup;

/**
 * What the edition's backup did, as plain facts. Core never reads the edition's backup code, files or secrets: the edition fills this in from its own
 * records (see {@see BackupFactsProviderInterface}) and Core only judges it.
 *
 *  - `available`: false when the edition cannot say at all (no backup subsystem, or it has not implemented the provider). Checks then answer
 *    "not available" instead of guessing.
 *  - `lastSuccessAt`: when the newest backup that completed successfully finished; null when none ever did.
 *  - `includedStores`: {@see BackupStore} ids that newest successful backup contains.
 *  - `destinations`: where that backup was written: `local` (on this server) and/or `offsite` (another server, object storage, tape: anything that
 *    survives the loss of this server).
 *  - `lastOffsiteSuccessAt`: when a copy last reached the off-site destination, null if never.
 *  - `encrypted`: true only if EVERY file of that backup was encrypted before it left the server; null when unknown.
 *  - `encryptionMethod`: a short label such as `age` or `gpg`; never a key, passphrase or recipient.
 *  - `retentionDays`: how long the edition keeps backup sets, null when unknown.
 *  - `includedTables`: optional detail (for example the RMM tables inside the control-database dump); informational.
 *
 * Honesty rules the conformance case checks: with no successful backup nothing else is claimed; no timestamp is in the future.
 *
 * @api
 */
final readonly class BackupFacts
{
    public const DEST_LOCAL = 'local';
    public const DEST_OFFSITE = 'offsite';

    /**
     * @param list<string> $includedStores
     * @param list<string> $destinations
     * @param list<string> $includedTables
     */
    public function __construct(
        public bool $available,
        public ?\DateTimeImmutable $lastSuccessAt = null,
        public array $includedStores = [],
        public array $destinations = [],
        public ?bool $encrypted = null,
        public ?int $retentionDays = null,
        public ?\DateTimeImmutable $lastOffsiteSuccessAt = null,
        public ?string $encryptionMethod = null,
        public ?RestoreTestFact $lastRestoreTest = null,
        public array $includedTables = [],
        public string $source = '',
        public string $reason = '',
    ) {
        foreach ($includedStores as $s) {
            if (!is_string($s) || !BackupStore::isValid($s)) {
                throw new \InvalidArgumentException('Unknown backup store in the backup facts.');
            }
        }
        foreach ($destinations as $d) {
            if ($d !== self::DEST_LOCAL && $d !== self::DEST_OFFSITE) {
                throw new \InvalidArgumentException('A backup destination is "local" or "offsite".');
            }
        }
        if ($retentionDays !== null && $retentionDays < 0) {
            throw new \InvalidArgumentException('The backup retention cannot be negative.');
        }
        if ($encryptionMethod !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._:+\/-]{0,39}\z/D', $encryptionMethod) !== 1) {
            throw new \InvalidArgumentException('The encryption method is a short label (letters, digits and . _ : + / - only), never a key.');
        }
        foreach ([$source, $reason] as $text) {
            if (mb_strlen($text) > 200 || preg_match('/[\x00-\x1f\x7f]/', $text) === 1) {
                throw new \InvalidArgumentException('Backup fact text must be one short line.');
            }
        }
        foreach ($includedTables as $t) {
            if (!is_string($t) || preg_match('/^[A-Za-z0-9_.]{1,64}\z/D', $t) !== 1) {
                throw new \InvalidArgumentException('A table name in the backup facts is not valid.');
            }
        }
    }

    /** The edition cannot report on its backup. */
    public static function unavailable(string $reason = 'This edition does not report backup information.'): self
    {
        return new self(false, reason: $reason);
    }

    public function hasOffsite(): bool
    {
        return in_array(self::DEST_OFFSITE, $this->destinations, true);
    }

    /** @return array<string,mixed> plain data for comparison and for the evidence in a report */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'last_success_at' => $this->lastSuccessAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'included_stores' => $this->includedStores,
            'destinations' => $this->destinations,
            'encrypted' => $this->encrypted,
            'retention_days' => $this->retentionDays,
            'last_offsite_success_at' => $this->lastOffsiteSuccessAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'encryption_method' => $this->encryptionMethod,
            'last_restore_test' => $this->lastRestoreTest === null ? null : [
                'tested_at' => $this->lastRestoreTest->testedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'passed' => $this->lastRestoreTest->passed,
                'stores' => $this->lastRestoreTest->stores, 'row_counts_match' => $this->lastRestoreTest->rowCountsMatch,
            ],
            'included_tables' => $this->includedTables,
            'source' => $this->source,
            'reason' => $this->reason,
        ];
    }
}
