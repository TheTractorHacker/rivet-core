<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Binaries;

use RivetCore\Rmm\Installer\InstallerStamp;
use RivetCore\Rmm\RmmProtocol;

/**
 * Header-level validation of a candidate agent executable, with no database, storage or module around it: PE (MZ, PE signature, machine
 * type, DLL flag) and ELF (class, byte order, machine) detection, the architecture check, the size window, the "already stamped" check and
 * the SHA-256. {@see BinaryStore::inspect()} and {@see BinaryStore::detect()} delegate here; call this class directly to validate a file
 * without constructing the module (tools, tests).
 *
 * @api
 */
final class BinaryInspector
{
    /** Bytes of the PE/ELF file header the detection needs. */
    private const HEADER_BYTES = 64;

    /** Machine type (e_machine) of the ELF architectures the agent is built for. */
    public const ELF_MACHINES = [62 => 'amd64', 183 => 'arm64'];

    /**
     * Identify an executable by its headers.
     *
     * @return array{format:string,machine:int,arch:?string,dll:bool,size:int}|string a description (format `pe` or `elf`; arch null for a machine type the agent is not built for) or an error message
     */
    public static function detect(string $path): array|string
    {
        if (!is_file($path) || !is_readable($path)) {
            return 'The file could not be read.';
        }
        $size = (int) filesize($path);
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return 'The file could not be read.';
        }
        $head = (string) fread($fh, self::HEADER_BYTES);
        if (strlen($head) >= 20 && str_starts_with($head, "\x7fELF")) {
            fclose($fh);
            $machine = (int) (unpack('v', substr($head, 18, 2))[1] ?? 0);
            $littleEndian = ord($head[5]) === 1;
            $is64 = ord($head[4]) === 2;
            if (!$littleEndian || !$is64) {
                return 'This is an ELF file, but not a 64-bit little-endian one.';
            }

            return ['format' => 'elf', 'machine' => $machine, 'arch' => self::ELF_MACHINES[$machine] ?? null, 'dll' => false, 'size' => $size];
        }
        if (strlen($head) < 64 || !str_starts_with($head, 'MZ')) {
            fclose($fh);

            return 'This is not a Windows executable (missing the MZ header).';
        }
        $peOff = (int) unpack('V', substr($head, 0x3C, 4))[1];
        if ($peOff < 64 || $peOff > 0x100000 || $peOff + 24 > $size) {
            fclose($fh);

            return 'This is not a valid Windows executable (bad PE header offset).';
        }
        fseek($fh, $peOff);
        $pe = (string) fread($fh, 24);
        fclose($fh);
        if (strlen($pe) < 24 || !str_starts_with($pe, "PE\0\0")) {
            return 'This is not a valid Windows executable (missing the PE signature).';
        }
        $machine = (int) unpack('v', substr($pe, 4, 2))[1];
        $characteristics = (int) unpack('v', substr($pe, 22, 2))[1];
        $arch = array_search($machine, RmmProtocol::ARCHS, true);

        return ['format' => 'pe', 'machine' => $machine, 'arch' => $arch === false ? null : (string) $arch, 'dll' => ($characteristics & 0x2000) !== 0, 'size' => $size];
    }

    /**
     * Validate a candidate Windows agent executable on disk. Reads only the headers and the last bytes (the SHA-256 streams the file).
     *
     * @param int $maxBytes the size cap ({@see BinaryStore::maxBytes()} for the module's)
     * @return array{sha256:string,size:int}|string a description on success, an error message otherwise
     */
    public static function inspect(string $path, string $arch, int $maxBytes): array|string
    {
        if (!isset(RmmProtocol::ARCHS[$arch])) {
            return 'The architecture must be amd64 or arm64.';
        }
        if (!is_file($path) || !is_readable($path)) {
            return 'The file could not be read.';
        }
        $size = (int) filesize($path);
        if ($size < RmmProtocol::BINARY_MIN_BYTES) {
            return 'The file is too small to be a Windows executable.';
        }
        if ($size > $maxBytes) {
            return 'The file is ' . BinaryStore::human($size) . ', larger than the ' . BinaryStore::human($maxBytes) . ' limit.';
        }
        $d = self::detect($path);
        if (is_string($d)) {
            return $d;
        }
        if ($d['format'] === 'elf') {
            return 'This is a Linux (ELF) executable. Only Windows agent executables can be hosted here; install the Linux agent from its release tarball with install-linux.sh.';
        }
        if ($d['machine'] !== RmmProtocol::ARCHS[$arch]) {
            return sprintf('The executable is built for machine type 0x%04X, which is not %s (expected 0x%04X).', $d['machine'], $arch, RmmProtocol::ARCHS[$arch]);
        }
        if ($d['dll']) {
            return 'This is a DLL, not an executable.';
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return 'The file could not be read.';
        }
        fseek($fh, -InstallerStamp::FOOTER_LEN, SEEK_END);
        $tail = (string) fread($fh, InstallerStamp::FOOTER_LEN);
        fclose($fh);
        if (InstallerStamp::hasFooter($tail)) {
            return 'This file already carries an installer footer (RIVETIT-EMBED). Upload the original, unstamped agent executable.';
        }
        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            return 'The file could not be read.';
        }

        return ['sha256' => $sha, 'size' => $size];
    }
}
