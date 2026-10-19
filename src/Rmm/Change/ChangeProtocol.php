<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Change;

/**
 * The names, caps and small pure rules of change monitoring (wire protocol 3.2.7 and 3.9, docs/rmm/CHANGE_MONITOR.md). Everything here is shared by the signed
 * configuration the server hands out, the ingest validation and the REST routes, so the two sides cannot drift apart.
 *
 * @api
 */
final class ChangeProtocol
{
    /** What an agent announces when it can watch the registry, configuration files, local groups and scheduled tasks (and what the server offers back). */
    public const CAP = 'change:monitor';
    /** What an agent announces when it can aggregate and filter Security log events by source (`aggregate`, level `always` in a log source); see {@see \RivetCore\Rmm\Logs\SecurityLogPresets}. */
    public const CAP_LOG_PRESETS = 'logs:presets';
    /** The sub-switch (RmmSettings::FEATURES). */
    public const FEATURE = 'change_monitor';

    public const BATCH_MAX = 100;
    public const BODY_MAX = 262144;
    /** agent_changes: requests per window (count, seconds) per device. */
    public const RATE = [30, 60];
    public const CONFIG_VERSION = 1;
    /** The signature of a change configuration covers this line, a line feed, then the canonical JSON of the object without its signature: a job or a check can never be replayed as one. */
    public const SIGN_DOMAIN = 'rivetit-change-config-v1';

    public const KINDS = ['registry', 'file', 'group', 'task'];
    /** Wire name => stored number. */
    public const CHANGE_TYPES = ['added' => 1, 'modified' => 2, 'removed' => 3];
    public const WATCH_ID_RE = '/^(?:win|lnx|custom)\.[a-z0-9_]{1,40}$/D';

    public const TARGET_MAX = 300;
    public const ITEM_MAX = 200;
    public const VALUE_MAX = 512;
    public const SUMMARY_MAX = 200;
    public const ACTOR_MAX = 120;
    public const PROCESS_MAX = 200;

    /** Record flags (stored in `flags`). */
    public const FLAG_REDACTED = 1;
    public const FLAG_TRUNCATED = 2;

    public const CHECK_PREFIX = 'change.rule_';
    public const MAX_CUSTOM_WATCHES = 30;
    public const MAX_PATHS_PER_WATCH = 10;
    /** The most bytes the signed configuration may take (it rides in the check-in response). */
    public const CONFIG_MAX_BYTES = 49152;

    public const MASK = \RivetCore\Rmm\RmmProtocol::REDACTION_MASK;

    public static function changeNumber(string $name): ?int
    {
        return self::CHANGE_TYPES[$name] ?? null;
    }

    public static function changeName(int $n): string
    {
        return (string) (array_search($n, self::CHANGE_TYPES, true) ?: 'modified');
    }

    /**
     * Value names whose content is never shown, stored or sent: they usually hold a secret. The agent applies the same rule before it reads anything, and the server
     * applies it again to what arrives.
     */
    public static function secretName(string $name): bool
    {
        return preg_match('/(pass(word|wd|phrase)?|pwd|secret|token|api[_-]?key|apikey|private[_-]?key|credential|connectionstring|authkey|accesskey|sharedkey|sas[_-]?token|bearer|cookie)/i', $name) === 1;
    }

    /**
     * The shared redactor applied to the text of a change record. In a SUDOERS file the tags `NOPASSWD:` and `PASSWD:` are not secrets (the redactor would take
     * "NOPASSWD: ALL" for a password and hide the one thing a sudoers change is about), so they are kept out of its sight; in any other record they are masked like any key.
     */
    public static function redact(string $s, bool $sudoers = false): string
    {
        if (!$sudoers) {
            return \RivetCore\Rmm\Crypto\Redactor::redact($s);
        }
        $marked = str_replace(['NOPASSWD:', 'PASSWD:'], ["NOPASSWD\u{E000}", "PASSWD\u{E000}"], $s);

        return str_replace("\u{E000}", ':', \RivetCore\Rmm\Crypto\Redactor::redact($marked));
    }

    /** Control characters become a space, invalid UTF-8 is dropped, the text is trimmed and cut to a character budget. */
    public static function text(string $s, int $max): string
    {
        $s = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        $s = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $s);

        return trim(mb_substr($s, 0, $max));
    }
}
