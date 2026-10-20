<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Hypervisor;

/**
 * The names, caps and small pure rules of hypervisor visibility (wire protocol 3.2.10, docs/rmm/HYPERVISORS.md). The signed configuration the server hands out and the ingest
 * validation share these, so the two sides cannot drift apart.
 *
 * @api
 */
final class HypervisorProtocol
{
    /** What an agent announces when it can collect Proxmox VE or Hyper-V data (and what the server offers back). Announcing it collects nothing: the server must offer it. */
    public const CAP = 'hypervisor';
    /** The sub-switch (RmmSettings::FEATURES). */
    public const FEATURE = 'hypervisor';
    public const CONFIG_VERSION = 1;
    /** The signature of a hypervisor configuration covers this line, a line feed, then the canonical JSON of the object without its signature. */
    public const SIGN_DOMAIN = 'rivetit-hypervisor-config-v1';

    public const PLATFORMS = ['proxmox', 'hyperv'];
    public const TYPES = ['qemu', 'lxc', 'hyperv'];
    public const STATES = ['running', 'stopped', 'paused', 'suspended', 'transitioning', 'error', 'unknown'];
    public const MODES = ['full', 'summary'];
    public const BACKUP_RESULTS = ['ok', 'warning', 'failed'];
    public const HEALTHS = ['ok', 'warn', 'error', 'unknown'];
    public const REPLICATION = ['normal', 'warning', 'critical'];

    /** The most VMs, storages and cluster nodes one report may carry (the server cuts what is over; the agent sends at most this many). */
    public const MAX_VMS = 1000;
    public const MAX_STORAGE = 200;
    public const MAX_NODES = 64;
    public const MAX_CHANGES = 1000;
    public const MAX_TAGS = 8;
    public const MAX_TAG = 40;
    public const NAME_MAX = 100;
    public const NODE_MAX = 64;
    public const KEY_MAX = 64;
    public const ERROR_MAX = 300;
    public const VERSION_MAX = 60;
    /** The most bytes the signed configuration may take. */
    public const CONFIG_MAX_BYTES = 2048;
    /** The agent keeps its block under this many bytes; the server refuses nothing for size alone (the check-in body cap of 1 MiB applies) but never stores more than the caps above. */
    public const REPORT_MAX_BYTES = 307200;
    public const MAX_BYTES_VALUE = 9007199254740992;
    public const MAX_UPTIME_S = 315360000;

    /** Check keys of the alerts this module raises (the pipeline allows `[A-Za-z0-9_.:-]{1,100}`). */
    public const CHECK_PREFIX = 'hv.';

    /** Control characters, bidi overrides, zero-width marks and line separators become a space; invalid UTF-8 is dropped; the text is trimmed and cut to a character budget. */
    public static function text(mixed $v, int $max): string
    {
        if (!is_string($v)) {
            return '';
        }
        $s = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $v);
        $s = (string) preg_replace('/[\x00-\x1f\x7f\x{80}-\x{9f}\x{200b}-\x{200f}\x{2028}-\x{202e}\x{2066}-\x{2069}\x{feff}]+/u', ' ', $s);

        return trim(mb_substr($s, 0, $max));
    }

    /** The same text made safe for a sentence in an alert or a webhook: markup characters and backticks go too, so a VM name cannot inject anything into a message. */
    public static function alertText(string $s, int $max = 100): string
    {
        return trim(mb_substr((string) preg_replace('/[<>`"\'\\\\]+/u', ' ', self::text($s, $max * 2)), 0, $max));
    }

    /** A check key part from free text: only characters the pipeline allows, short, never empty. */
    public static function keyPart(string $s): string
    {
        $p = (string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $s);
        $p = trim($p, '-');

        return $p === '' ? substr(sha1($s), 0, 8) : substr($p, 0, 40) . (strlen($p) > 40 || $p !== $s ? '-' . substr(sha1($s), 0, 6) : '');
    }

    public static function vmCheckKey(string $kind, string $vmKey): string
    {
        return self::CHECK_PREFIX . $kind . '.' . self::keyPart($vmKey);
    }

    public static function platformOfOs(string $os): string
    {
        return $os === 'windows' ? 'windows' : 'linux';
    }
}
