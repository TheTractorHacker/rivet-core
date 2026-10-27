<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Check;

use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Profile\DataClass;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\RequirementType;

/**
 * Tamper evidence on audit events, RMM logs and change records. PLACEHOLDER: the integrity chain for the hot data is phase 3 of #92 and does not exist
 * yet, so a profile that asks for it gets an honest "not met: not available in this release", never a pass. When phase 3 lands this check reads the real
 * verification instead; its id and title stay.
 *
 * @api
 */
final class IntegrityChainCheck extends AbstractProfileCheck
{
    public function id(): string
    {
        return 'integrity_chain';
    }

    public function title(): string
    {
        return 'Logs show tampering (integrity chain)';
    }

    public function category(): string
    {
        return 'Logging and monitoring';
    }

    public function why(): string
    {
        return 'Logs are only evidence if an alteration or a deleted record can be detected afterwards.';
    }

    public function controls(): array
    {
        return ['iso27001' => ['A.8.15'], 'soc2' => ['CC7.2'], 'pci' => ['10.3.4'], 'hipaa' => ['164.312(c)(1)'], 'nist171' => ['3.3.8']];
    }

    public function requirementTypes(): array
    {
        return [RequirementType::TAMPER_EVIDENCE];
    }

    protected function evaluate(FrameworkProfile $profile): CheckResult
    {
        $names = array_map(static fn ($r): string => DataClass::label((string) $r->dataClass), $profile->ofType(RequirementType::TAMPER_EVIDENCE));

        return CheckResult::fail(
            'Not available: tamper evidence is not implemented in this release.',
            "Not met: the framework asks for tamper evidence on " . implode(', ', $names) . ".\nEvidence: no integrity chain exists for these records yet (planned as phase 3 of the customizable frameworks work).",
            $this->settingsPath ?: null,
            ['implemented' => false],
        );
    }
}
