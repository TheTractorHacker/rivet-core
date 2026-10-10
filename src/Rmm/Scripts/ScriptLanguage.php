<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Scripts;

/**
 * The languages the script library accepts, the job type each one is delivered as, and the operating systems it runs on. Every job runs as
 * the agent's service account (SYSTEM on Windows, root on Linux): there is no run-as choice.
 *
 * @api
 */
final class ScriptLanguage
{
    public const POWERSHELL = 'powershell';
    public const BASH = 'bash';
    public const PYTHON = 'python';

    /** language => [job type, platforms] */
    private const MAP = [
        self::POWERSHELL => ['powershell', ['windows']],
        self::BASH => ['shell', ['linux']],
        self::PYTHON => ['python', ['linux']],
    ];

    /** A PowerShell script travels in an -EncodedCommand (the Windows command line is 32,767 characters), so it is capped well below the generic script size. */
    public const POWERSHELL_MAX_CHARS = 10000;

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::MAP);
    }

    public static function valid(string $language): bool
    {
        return isset(self::MAP[$language]);
    }

    /** The job type (`powershell`, `shell`, `python`) the language is delivered as. */
    public static function jobType(string $language): string
    {
        return self::MAP[$language][0] ?? '';
    }

    /** @return list<string> */
    public static function platforms(string $language): array
    {
        return self::MAP[$language][1] ?? [];
    }

    /** The platform column a script of this language gets ("windows", "linux"). */
    public static function defaultPlatform(string $language): string
    {
        return self::MAP[$language][1][0] ?? '';
    }
}
