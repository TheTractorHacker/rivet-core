<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Binaries;

use RivetCore\Rmm\Installer\InstallerStamp;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Support\AgentOs;

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
     * Validate a candidate agent executable on disk, for the platform it is declared for: `windows` (PE; the default) or `linux` (ELF: 64-bit little-endian executable
     * or PIE, machine matching the architecture, and, when a version is given, that version string inside the file). A file whose magic is the other platform's is refused
     * with a message that says so. Reads only the headers and the last bytes (the SHA-256 streams the file).
     *
     * @param int $maxBytes the size cap ({@see BinaryStore::maxBytes()} for the module's)
     * @param string $os `windows` or `linux`
     * @param string|null $version the version the file is published as; for `linux` it must appear in the file (the agent embeds it)
     * @return array{sha256:string,size:int}|string a description on success, an error message otherwise
     */
    public static function inspect(string $path, string $arch, int $maxBytes, string $os = AgentOs::WINDOWS, ?string $version = null): array|string
    {
        if (!in_array($os, AgentOs::ALL, true)) {
            return 'The platform must be windows or linux.';
        }
        if ($os === AgentOs::LINUX) {
            return self::inspectLinux($path, $arch, $maxBytes, $version);
        }
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
            return 'This is a Linux (ELF) executable, but it was published as a Windows agent. Publish it with the platform set to linux.';
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
    /**
     * @return array{sha256:string,size:int}|string
     */
    private static function inspectLinux(string $path, string $arch, int $maxBytes, ?string $version): array|string
    {
        if (!isset(RmmProtocol::ARCHS[$arch])) {
            return 'The architecture must be amd64 or arm64.';
        }
        if (!is_file($path) || !is_readable($path)) {
            return 'The file could not be read.';
        }
        $size = (int) filesize($path);
        if ($size < RmmProtocol::BINARY_MIN_BYTES) {
            return 'The file is too small to be a Linux executable.';
        }
        if ($size > $maxBytes) {
            return 'The file is ' . BinaryStore::human($size) . ', larger than the ' . BinaryStore::human($maxBytes) . ' limit.';
        }
        $d = self::detect($path);
        if (is_string($d)) {
            return str_contains($d, 'MZ header') ? 'This is not a Linux executable (it does not start with the ELF magic).' : $d;
        }
        if ($d['format'] === 'pe') {
            return 'This is a Windows (PE) executable, but it was published as a Linux agent. Publish it with the platform set to windows.';
        }
        if ($d['arch'] === null) {
            return sprintf('The executable is built for ELF machine type %d, which is neither x86-64 (amd64) nor aarch64 (arm64).', $d['machine']);
        }
        if ($d['arch'] !== $arch) {
            return sprintf('The executable is built for %s, not %s.', $d['arch'], $arch);
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return 'The file could not be read.';
        }
        $head = (string) fread($fh, 20);
        $type = (int) (unpack('v', substr($head, 16, 2))[1] ?? 0);   // e_type: 2 executable, 3 shared object (a PIE executable)
        if ($type !== 2 && $type !== 3) {
            fclose($fh);

            return 'This ELF file is not an executable (it is an object file, a core dump or another kind of file).';
        }
        fseek($fh, -InstallerStamp::FOOTER_LEN, SEEK_END);
        $tail = (string) fread($fh, InstallerStamp::FOOTER_LEN);
        fclose($fh);
        if (InstallerStamp::hasFooter($tail)) {
            return 'This file already carries an installer footer (RIVETIT-EMBED). Upload the original, unstamped agent executable.';
        }
        if ($version !== null && $version !== '' && !self::containsString($path, $version)) {
            return "The executable does not contain the version string \"$version\". Check that you picked the right file and version.";
        }
        $sha = hash_file('sha256', $path);

        return $sha === false ? 'The file could not be read.' : ['sha256' => $sha, 'size' => $size];
    }

    /** Whether the file contains these bytes (streamed in 1 MiB blocks with an overlap, so memory stays flat). */
    public static function containsString(string $path, string $needle): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false || $needle === '') {
            return false;
        }
        $keep = strlen($needle) - 1;
        $carry = '';
        while (!feof($fh)) {
            $chunk = fread($fh, 1048576);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf = $carry . $chunk;
            if (str_contains($buf, $needle)) {
                fclose($fh);

                return true;
            }
            $carry = $keep > 0 ? substr($buf, -$keep) : '';
        }
        fclose($fh);

        return false;
    }
}
