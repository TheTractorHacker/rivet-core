<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Installer;

/**
 * The formats of a per-client installer: `exe` (the stamped agent executable, the default and the only one before 1.0.0-rc.16) and `msi` (a copy of a published MSI
 * template with the SAME payload || footer trailer appended after its last byte). The trailer format is {@see InstallerStamp}, unchanged.
 *
 * @api
 */
final class InstallerFormat
{
    public const EXE = 'exe';
    public const MSI = 'msi';
    public const ALL = [self::EXE, self::MSI];

    /** The architectures an MSI template can be published for. Only amd64 can be BUILT today (msitools' wixl has no arm64 target); an arm64 template built elsewhere is accepted. */
    public const MSI_ARCHS = ['amd64', 'arm64'];

    public static function valid(mixed $format): bool
    {
        return is_string($format) && in_array($format, self::ALL, true);
    }

    /** Normalise a requested format; null (not given) means exe. @return string|null null when the value is not a known format */
    public static function normalize(mixed $format): ?string
    {
        if ($format === null || $format === '') {
            return self::EXE;
        }

        return self::valid($format) ? $format : null;
    }

    public static function extension(string $format): string
    {
        return $format === self::MSI ? 'msi' : 'exe';
    }
}
