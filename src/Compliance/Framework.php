<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

/** The frameworks controls can be tagged to. Keys match RetentionPolicy::PROFILES. */
final class Framework
{
    public const ISO27001 = 'iso27001';
    public const SOC2 = 'soc2';
    public const PCI = 'pci';
    public const HIPAA = 'hipaa';
    public const NIST171 = 'nist171';

    public const LABELS = [
        self::ISO27001 => 'ISO/IEC 27001:2022',
        self::SOC2 => 'SOC 2 (2017 Trust Services Criteria)',
        self::PCI => 'PCI DSS v4.0',
        self::HIPAA => 'HIPAA Security Rule',
        self::NIST171 => 'NIST SP 800-171 Rev 2 / CMMC Level 2',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(string $framework): bool
    {
        return isset(self::LABELS[$framework]);
    }
}
