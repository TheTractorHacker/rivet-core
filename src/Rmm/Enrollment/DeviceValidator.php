<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Enrollment;

use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\RmmProtocol;

/**
 * The pure validation and normalisation rules for the device block an agent sends at enrollment (and the text helpers the
 * check-in uses). No database, no clock. Ported 1:1 from RivetIT Enrollment::{normalizeMac,cleanSerial,cleanText,validateDevice}.
 *
 * @api
 */
final class DeviceValidator
{
    /** Serial numbers firmware vendors ship as placeholders; a match on one of these is never an identity. */
    public const JUNK_SERIALS = RmmProtocol::JUNK_SERIALS;

    /** Lower-case colon form of a MAC, or null for anything else including the null MAC. */
    public static function normalizeMac(string $mac): ?string
    {
        $m = strtolower(str_replace('-', ':', trim($mac)));
        if (preg_match(RmmProtocol::MAC_RE, $m) !== 1 || $m === RmmProtocol::NULL_MAC) {
            return null;
        }

        return $m;
    }

    /** The trimmed serial, or null when it is empty or one of JUNK_SERIALS (case-insensitive). */
    public static function cleanSerial(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = trim($s);

        return in_array(strtolower($s), self::JUNK_SERIALS, true) ? null : $s;
    }

    /** Control characters become spaces, the result is trimmed and cut to $max characters; null for non-strings and empty text. */
    public static function cleanText(mixed $v, int $max): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $v) ?? '';
        $v = trim($v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /**
     * Validate and normalise the device block of an enrollment request.
     *
     * @param array<string,mixed> $d
     * @param bool $allowLinux admit os "linux" (RivetIT's EA_ALLOW_NON_WINDOWS test flag; never true in production)
     * @return array{install_id:string,machine_guid:?string,hostname:string,os_version:string,arch:string,serial:?string,manufacturer:?string,model:?string,macs:list<string>,agent_version:string}
     * @throws ApiError 422 invalid, message "device.<field> is invalid"
     */
    public static function validateDevice(array $d, bool $allowLinux = false): array
    {
        $bad = static fn (string $f): ApiError => new ApiError(422, 'invalid', "device.$f is invalid");
        $install = $d['install_id'] ?? null;
        if (!is_string($install) || preg_match(RmmProtocol::INSTALL_ID_RE, $install) !== 1) {
            throw $bad('install_id');
        }
        $guid = $d['machine_guid'] ?? null;
        if ($guid !== null && (!is_string($guid) || preg_match(RmmProtocol::MACHINE_GUID_RE, $guid) !== 1)) {
            throw $bad('machine_guid');
        }
        $host = self::cleanText($d['hostname'] ?? null, 200);
        if ($host === null) {
            throw $bad('hostname');
        }
        $os = $d['os'] ?? '';
        if (!($os === 'windows' || ($allowLinux && $os === 'linux'))) {
            throw $bad('os');
        }
        $arch = $d['arch'] ?? '';
        if (!is_string($arch) || !array_key_exists($arch, RmmProtocol::ARCHS)) {
            throw $bad('arch');
        }
        $ver = $d['agent_version'] ?? null;
        if (!is_string($ver) || preg_match(RmmProtocol::AGENT_VERSION_RE, $ver) !== 1) {
            throw $bad('agent_version');
        }
        $macsIn = $d['mac_addresses'] ?? [];
        if (!is_array($macsIn) || count($macsIn) > 32) {
            throw $bad('mac_addresses');
        }
        $macs = [];
        foreach ($macsIn as $m) {
            $n = is_string($m) ? self::normalizeMac($m) : null;
            if ($n !== null) {
                $macs[$n] = $n;
            }
        }

        return [
            'install_id' => strtolower($install),
            'machine_guid' => $guid === null ? null : strtolower($guid),
            'hostname' => $host,
            'os_version' => self::cleanText($d['os_version'] ?? '', 200) ?? '',
            'arch' => $arch,
            'serial' => self::cleanSerial(self::cleanText($d['serial'] ?? null, 100)),
            'manufacturer' => self::cleanText($d['manufacturer'] ?? null, 200),
            'model' => self::cleanText($d['model'] ?? null, 200),
            'macs' => array_values($macs),
            'agent_version' => $ver,
        ];
    }
}
