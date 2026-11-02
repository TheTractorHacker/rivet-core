<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Audit\AuditChainResult;
use RivetCore\Audit\AuditChainVerifier;
use RivetCore\Compliance\CheckInterface;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Framework;

/**
 * Is the audit log's hash chain intact? Wraps AuditChainVerifier. Not part of any built-in profile (a built-in must not start failing on
 * an install that has not adopted the chain); an edition adds it to its catalog once it has run migration 0048 and enabled chaining.
 *
 * @api
 */
final class AuditChainIntactCheck implements CheckInterface
{
    public function __construct(private AuditChainVerifier $verifier, private string $fixPath = '')
    {
    }

    public function id(): string
    {
        return 'audit_chain_intact';
    }

    public function title(): string
    {
        return 'Audit log is tamper-evident and intact';
    }

    public function category(): string
    {
        return 'Logging and monitoring';
    }

    public function why(): string
    {
        return 'An audit trail is only evidence if edits, deleted rows and truncation can be detected. Each row is chained to the one before it.';
    }

    public function controls(): array
    {
        return [
            Framework::ISO27001 => ['A.8.15'],
            Framework::SOC2 => ['CC7.2'],
            Framework::PCI => ['10.3.2'],
            Framework::HIPAA => ['164.312(c)(1)'],
        ];
    }

    public function run(): CheckResult
    {
        $r = $this->verifier->verify();
        $fix = $this->fixPath !== '' ? $this->fixPath : null;
        $metrics = ['chained' => $r->chained, 'legacy_unchained' => $r->legacyUnchained, 'unchained_after_start' => $r->unchainedAfterStart, 'first_broken_id' => $r->firstBrokenId];

        return match ($r->status) {
            AuditChainResult::OK => CheckResult::pass("Audit hash chain verified ({$r->chained} rows" . ($r->legacyUnchained > 0 ? ", {$r->legacyUnchained} older rows pre-date the chain and are not covered" : '') . ').', 'A database administrator who rewrites the whole chain is not detected unless the head hash is stored off the database.', $metrics),
            AuditChainResult::EMPTY => CheckResult::warn('The audit hash chain has not started: no audit row has been chained yet.', $r->reason, $fix, $metrics),
            AuditChainResult::KEY_UNAVAILABLE, AuditChainResult::KEY_MISMATCH, AuditChainResult::NOT_INSTALLED => CheckResult::warn('The audit hash chain could not be verified.', $r->reason, $fix, $metrics),
            default => CheckResult::fail('The audit hash chain is broken.', $r->reason, $fix, $metrics),
        };
    }
}
