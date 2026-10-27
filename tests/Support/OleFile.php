<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

/**
 * A minimal OLE compound file (version 3, 512-byte sectors) holding one stream, `\005SummaryInformation`, with string properties: just enough of a Windows Installer
 * package for the MSI inspector's tests (real wixl output is in tests/Fixtures/rmm/msi/template-x64.msi). The summary stream is small, so it lives in the mini stream
 * like a real one.
 */
final class OleFile
{
    private const END = 0xFFFFFFFE;
    private const FREE = 0xFFFFFFFF;

    /**
     * @param array<int,string> $props property id => string (7 = Template, 3 = Subject, ...)
     * @param int $padTo the file is zero-padded to this size (the inspector has a minimum)
     */
    public static function build(array $props, int $padTo = 9000): string
    {
        $stream = self::summaryStream($props);
        $miniCount = intdiv(strlen($stream) + 63, 64);
        $mini = str_pad($stream, $miniCount * 64, "\0");
        $containerSectors = max(1, intdiv(strlen($mini) + 511, 512));
        $container = str_pad($mini, $containerSectors * 512, "\0");

        $fat = array_fill(0, 128, self::FREE);
        $fat[0] = 0xFFFFFFFD;   // the FAT sector itself
        $fat[1] = self::END;    // directory
        $fat[2] = self::END;    // mini FAT
        for ($i = 0; $i < $containerSectors; ++$i) {
            $fat[3 + $i] = $i === $containerSectors - 1 ? self::END : 3 + $i + 1;
        }
        $miniFat = array_fill(0, 128, self::FREE);
        for ($i = 0; $i < $miniCount; ++$i) {
            $miniFat[$i] = $i === $miniCount - 1 ? self::END : $i + 1;
        }

        $dir = self::entry("Root Entry", 5, 1, 3, $containerSectors * 512 > 0 ? $miniCount * 64 : 0)
            . self::entry("\x05SummaryInformation", 2, self::FREE, 0, strlen($stream))
            . str_repeat("\0", 256);

        $hdr = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16) . pack('vvvvv', 0x3E, 3, 0xFFFE, 9, 6) . str_repeat("\0", 6)
            . pack('VVVVVVVVVV', 0, 1, 1, 0, 4096, 2, 1, self::END, 0, 0)
            . str_repeat(pack('V', self::FREE), 108);
        // header DIFAT[0] is the FAT sector (sector 0): it sits at byte 76, i.e. replace the first of the 109 entries
        $hdr = substr($hdr, 0, 76) . pack('V', 0) . substr($hdr, 80);
        $out = str_pad($hdr, 512, "\0") . pack('V*', ...$fat) . str_pad($dir, 512, "\0") . pack('V*', ...$miniFat) . $container;

        return str_pad($out, max($padTo, strlen($out)), "\0");
    }

    /** @param array<int,string> $props */
    private static function summaryStream(array $props): string
    {
        $vals = '';
        $table = '';
        $base = 8 + 8 * count($props);
        foreach ($props as $id => $v) {
            $table .= pack('VV', $id, $base + strlen($vals));
            $s = $v . "\0";
            $vals .= pack('VV', 0x1E, strlen($s)) . $s . str_repeat("\0", (4 - strlen($s) % 4) % 4);
        }
        $section = pack('VV', $base + strlen($vals), count($props)) . $table . $vals;

        return "\xFE\xFF" . pack('vV', 0, 0x00020006) . str_repeat("\0", 16) . pack('V', 1) . hex2bin('E0859FF2F94F6810AB9108002B27B3D9') . pack('V', 48) . $section;
    }

    private static function entry(string $name, int $type, int $child, int $start, int $size): string
    {
        $u = (string) iconv('UTF-8', 'UTF-16LE', $name);

        return str_pad($u, 64, "\0") . pack('v', strlen($u) + 2) . chr($type) . chr(1) . pack('VVV', self::FREE, self::FREE, $child) . str_repeat("\0", 16)
            . pack('V', 0) . str_repeat("\0", 16) . pack('V', $start) . pack('VV', $size, 0);
    }
}
