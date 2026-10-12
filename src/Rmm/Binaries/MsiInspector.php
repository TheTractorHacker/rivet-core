<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Binaries;

use RivetCore\Rmm\Installer\InstallerStamp;

/**
 * Header-level validation of a candidate MSI template, with no database or storage around it: a Windows Installer database is an OLE compound file (signature
 * D0 CF 11 E0 A1 B1 1A E1), and its summary-information stream (`\005SummaryInformation`) names the platform in its Template property (`x64;1033`, `Arm64;1033`,
 * `Intel;1033`). This reads only that: the compound-file header, the allocation tables and the one small stream. It never parses the installer tables and never
 * executes anything. A file that is not parsable as far as the summary stream is still accepted when the caller declares the architecture (the declared value is
 * then the only evidence, and the stored row says so); a parsable summary that disagrees with the declaration is refused.
 *
 * @api
 */
final class MsiInspector
{
    public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    public const MIN_BYTES = 8192;
    /** Property set limits: nothing in a legitimate summary stream comes near these. */
    private const MAX_STREAM = 1048576;
    private const ENDOFCHAIN = 0xFFFFFFFE;
    private const FREESECT = 0xFFFFFFFF;

    /** Windows Installer platform name (lower case) => agent architecture. */
    public const PLATFORMS = ['x64' => 'amd64', 'arm64' => 'arm64'];

    /**
     * Read the platform out of the summary stream.
     *
     * @return array{template:?string,platform:?string,arch:?string,subject:?string,note:?string} template is the raw property (`x64;1033`), platform the part before the `;` lower-cased,
     *         arch the agent architecture it means (null for a platform the agent is not built for), note why the stream could not be read (null when it was)
     */
    public static function summary(string $path): array
    {
        $none = ['template' => null, 'platform' => null, 'arch' => null, 'subject' => null, 'note' => null];
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return ['note' => 'The file could not be read.'] + $none;
        }
        try {
            $props = self::summaryProperties($fh);
        } catch (\RuntimeException $e) {
            return ['note' => $e->getMessage()] + $none;
        } finally {
            fclose($fh);
        }
        $template = $props[7] ?? null;
        $platform = null;
        $arch = null;
        if ($template !== null) {
            $first = strtolower(trim(explode(';', $template)[0]));
            $platform = $first === '' ? null : $first;
            $arch = $platform !== null ? (self::PLATFORMS[$platform] ?? null) : null;
        }

        return ['template' => $template, 'platform' => $platform, 'arch' => $arch, 'subject' => $props[3] ?? null, 'note' => $template === null ? 'The summary information has no Template property.' : null];
    }

    /**
     * Validate a candidate MSI template on disk.
     *
     * @param string $arch the architecture the caller declares (amd64 or arm64)
     * @return array{sha256:string,size:int,arch:string,arch_source:string,template:?string}|string a description on success, an error message otherwise
     */
    public static function inspect(string $path, string $arch, int $maxBytes): array|string
    {
        if (!in_array($arch, ['amd64', 'arm64'], true)) {
            return 'The architecture must be amd64 or arm64.';
        }
        if (!is_file($path) || !is_readable($path)) {
            return 'The file could not be read.';
        }
        $size = (int) filesize($path);
        if ($size < self::MIN_BYTES) {
            return 'The file is too small to be a Windows Installer package.';
        }
        if ($size > $maxBytes) {
            return 'The file is ' . BinaryStore::human($size) . ', larger than the ' . BinaryStore::human($maxBytes) . ' limit.';
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return 'The file could not be read.';
        }
        $head = (string) fread($fh, 8);
        if ($head !== self::SIGNATURE) {
            fclose($fh);
            if (str_starts_with($head, 'MZ') || str_starts_with($head, "\x7fELF")) {
                return 'This is an executable, not an MSI package. Upload agent executables under Agent binaries.';
            }

            return 'This is not a Windows Installer package (it is not an OLE compound file: D0CF11E0A1B11AE1).';
        }
        fseek($fh, -InstallerStamp::FOOTER_LEN, SEEK_END);
        $tail = (string) fread($fh, InstallerStamp::FOOTER_LEN);
        fclose($fh);
        if (InstallerStamp::hasFooter($tail)) {
            return 'This file already carries an installer footer (RIVETIT-EMBED). Upload the original, unstamped MSI template.';
        }
        $s = self::summary($path);
        $source = 'declared';
        if ($s['platform'] !== null) {
            if ($s['arch'] === null) {
                return 'This package is built for platform "' . $s['platform'] . '" (' . (string) $s['template'] . '), which the agent does not support. Only x64 and Arm64 packages can be hosted.';
            }
            if ($s['arch'] !== $arch) {
                return 'The package says it is for ' . $s['arch'] . ' (' . (string) $s['template'] . '), not ' . $arch . '.';
            }
            $source = 'summary';
        }
        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            return 'The file could not be read.';
        }

        return ['sha256' => $sha, 'size' => $size, 'arch' => $arch, 'arch_source' => $source, 'template' => $s['template']];
    }

    // ------------------------------------------------------------------ the compound file

    /**
     * @param resource $fh
     * @return array<int,string> property id => string value (only the string properties of the first section)
     */
    private static function summaryProperties($fh): array
    {
        $size = (int) (fstat($fh)['size'] ?? 0);
        $hdr = self::read($fh, 0, 512);
        if (strlen($hdr) < 512 || !str_starts_with($hdr, self::SIGNATURE)) {
            throw new \RuntimeException('This is not an OLE compound file.');
        }
        $u16 = static fn (string $b, int $o): int => (int) unpack('v', substr($b, $o, 2))[1];
        $u32 = static fn (string $b, int $o): int => (int) unpack('V', substr($b, $o, 4))[1];
        if ($u16($hdr, 28) !== 0xFFFE) {
            throw new \RuntimeException('Unsupported byte order in the compound file.');
        }
        $shift = $u16($hdr, 30);
        $miniShift = $u16($hdr, 32);
        if (!in_array($shift, [9, 12], true) || $miniShift !== 6) {
            throw new \RuntimeException('Unsupported sector size in the compound file.');
        }
        $ss = 1 << $shift;
        $maxSectors = intdiv($size, $ss) + 2;
        $numFat = $u32($hdr, 44);
        $firstDir = $u32($hdr, 48);
        $cutoff = $u32($hdr, 56);
        $firstMini = $u32($hdr, 60);
        $firstDifat = $u32($hdr, 68);
        $numDifat = $u32($hdr, 72);
        if ($numFat < 1 || $numFat > $maxSectors || $numDifat > $maxSectors) {
            throw new \RuntimeException('The compound file header is inconsistent.');
        }
        $offset = static fn (int $sector): int => ($sector + 1) << $shift;

        // the sectors that hold the FAT: 109 in the header, then the DIFAT chain
        $fatSectors = [];
        for ($i = 0; $i < 109 && count($fatSectors) < $numFat; ++$i) {
            $s = $u32($hdr, 76 + 4 * $i);
            if ($s !== self::FREESECT) {
                $fatSectors[] = $s;
            }
        }
        $d = $firstDifat;
        for ($n = 0; $n < $numDifat && $d !== self::ENDOFCHAIN && $d !== self::FREESECT && count($fatSectors) < $numFat; ++$n) {
            $blk = self::read($fh, $offset($d), $ss);
            if (strlen($blk) < $ss) {
                throw new \RuntimeException('The compound file is truncated.');
            }
            for ($i = 0; $i < $ss / 4 - 1 && count($fatSectors) < $numFat; ++$i) {
                $s = $u32($blk, 4 * $i);
                if ($s !== self::FREESECT) {
                    $fatSectors[] = $s;
                }
            }
            $d = $u32($blk, $ss - 4);
        }
        $fat = [];
        foreach ($fatSectors as $s) {
            $blk = self::read($fh, $offset($s), $ss);
            if (strlen($blk) < $ss) {
                throw new \RuntimeException('The compound file is truncated.');
            }
            foreach (unpack('V*', $blk) ?: [] as $e) {
                $fat[] = (int) $e;
            }
        }
        $chain = static function (int $start, array $table) use ($maxSectors): array {
            $out = [];
            $s = $start;
            while ($s !== self::ENDOFCHAIN && $s !== self::FREESECT) {
                if (!isset($table[$s]) || count($out) > $maxSectors) {
                    throw new \RuntimeException('The compound file has a broken sector chain.');
                }
                $out[] = $s;
                $s = $table[$s];
            }

            return $out;
        };
        $readChain = function (int $start) use ($fh, $chain, $fat, $offset, $ss): string {
            $buf = '';
            foreach ($chain($start, $fat) as $s) {
                $buf .= self::read($fh, $offset($s), $ss);
            }

            return $buf;
        };
        $dir = $readChain($firstDir);
        if (strlen($dir) < 128) {
            throw new \RuntimeException('The compound file has no directory.');
        }
        $root = null;
        $target = null;
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $len = $u16($dir, $o + 64);
            $type = ord($dir[$o + 66]);
            if ($len < 2 || $len > 64 || $type === 0) {
                continue;
            }
            $name = (string) @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($dir, $o, $len - 2));
            $ent = ['start' => $u32($dir, $o + 116), 'size' => $u32($dir, $o + 120) + ($u32($dir, $o + 124) << 32)];
            if ($type === 5 && $root === null) {
                $root = $ent;
            }
            if ($type === 2 && $name === "\x05SummaryInformation" && $target === null) {
                $target = $ent;
            }
        }
        if ($target === null) {
            throw new \RuntimeException('The package has no summary-information stream.');
        }
        if ($target['size'] < 48 || $target['size'] > self::MAX_STREAM) {
            throw new \RuntimeException('The summary-information stream has an unexpected size.');
        }
        if ($target['size'] < $cutoff) {
            // a small stream lives in the mini stream (64-byte sectors) held by the root entry
            if ($root === null || $firstMini === self::ENDOFCHAIN) {
                throw new \RuntimeException('The package has no mini stream.');
            }
            $miniFat = [];
            foreach (unpack('V*', $readChain($firstMini)) ?: [] as $e) {
                $miniFat[] = (int) $e;
            }
            $container = $readChain($root['start']);
            $data = '';
            foreach ($chain($target['start'], $miniFat) as $s) {
                $data .= substr($container, $s << 6, 64);
            }
        } else {
            $data = $readChain($target['start']);
        }
        $data = substr($data, 0, $target['size']);

        return self::propertySet($data);
    }

    /**
     * @return array<int,string>
     */
    private static function propertySet(string $b): array
    {
        $u32 = static fn (string $s, int $o): int => strlen($s) >= $o + 4 ? (int) unpack('V', substr($s, $o, 4))[1] : -1;
        if (strlen($b) < 48 || substr($b, 0, 2) !== "\xFE\xFF") {
            throw new \RuntimeException('The summary information is not a property set.');
        }
        $sec = $u32($b, 44);
        $count = $u32($b, $sec + 4);
        if ($sec < 48 || $sec >= strlen($b) || $count < 0 || $count > 64) {
            throw new \RuntimeException('The summary information is not a property set.');
        }
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            $id = $u32($b, $sec + 8 + 8 * $i);
            $off = $sec + $u32($b, $sec + 12 + 8 * $i);
            if ($off < 0 || $off + 8 > strlen($b)) {
                continue;
            }
            $type = $u32($b, $off) & 0xFFFF;
            if ($type !== 0x1E) {   // VT_LPSTR
                continue;
            }
            $len = $u32($b, $off + 4);
            if ($len < 1 || $len > 4096 || $off + 8 + $len > strlen($b)) {
                continue;
            }
            $v = rtrim(substr($b, $off + 8, $len), "\0");
            if (preg_match('/^[\x20-\x7e]*$/', $v) === 1) {
                $out[$id] = $v;
            }
        }

        return $out;
    }

    /**
     * @param resource $fh
     */
    private static function read($fh, int $offset, int $length): string
    {
        if ($offset < 0 || fseek($fh, $offset) !== 0) {
            return '';
        }

        return (string) fread($fh, $length);
    }
}
