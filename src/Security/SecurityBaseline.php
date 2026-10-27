<?php

declare(strict_types=1);

namespace RivetCore\Security;

use RivetCore\Rmm\Store\Postgres\PostgresConfig;

/**
 * The secure-by-default values and non-lowerable floors of the RivetCore security baseline (docs/security/BASELINE.md, controls
 * HARD-*). Pure functions and constants: no I/O, no state, so an edition can call them anywhere and a test can pin every number.
 *
 * Three kinds of setting:
 *  - locked: always on, no setter exists (audit trail, encrypted credentials, TLS only, Secure/HttpOnly cookies);
 *  - floored: adjustable, but never below the floor (audit retention >= 90 days, password length >= 12) or above the ceiling
 *    (session idle timeout <= 480 minutes);
 *  - adjustable with a secure default (MFA for non-administrators).
 *
 * Every change to a floored or adjustable setting goes through {@see BaselineChanges}, which audit-logs it.
 *
 * @api
 */
final class SecurityBaseline
{
    public const MIN_AUDIT_RETENTION_DAYS = 90;
    public const DEFAULT_AUDIT_RETENTION_DAYS = 365;

    public const MIN_PASSWORD_LENGTH = 12;
    public const DEFAULT_PASSWORD_LENGTH = 14;
    /** Verifiers must accept at least this many characters (long passphrases and password-manager output). */
    public const MIN_PASSWORD_MAX_ACCEPTED = 64;

    public const DEFAULT_SESSION_IDLE_MINUTES = 30;
    public const MAX_SESSION_IDLE_MINUTES = 480;

    public const DEFAULT_COOKIE_SAMESITE = 'Lax';

    /**
     * Setting key => definition. `locked` settings have no legal value other than `default`.
     *
     * @var array<string, array{type:'int'|'bool', default:int|bool, min?:int, max?:int, locked:bool}>
     */
    public const SETTINGS = [
        'audit_enabled' => ['type' => 'bool', 'default' => true, 'locked' => true],
        'credential_encryption' => ['type' => 'bool', 'default' => true, 'locked' => true],
        'tls_only' => ['type' => 'bool', 'default' => true, 'locked' => true],
        'cookie_secure' => ['type' => 'bool', 'default' => true, 'locked' => true],
        'cookie_httponly' => ['type' => 'bool', 'default' => true, 'locked' => true],
        'mfa_required_administrators' => ['type' => 'bool', 'default' => true, 'locked' => true],
        'mfa_required_users' => ['type' => 'bool', 'default' => true, 'locked' => false],
        'audit_retention_days' => ['type' => 'int', 'default' => self::DEFAULT_AUDIT_RETENTION_DAYS, 'min' => self::MIN_AUDIT_RETENTION_DAYS, 'locked' => false],
        'password_min_length' => ['type' => 'int', 'default' => self::DEFAULT_PASSWORD_LENGTH, 'min' => self::MIN_PASSWORD_LENGTH, 'locked' => false],
        'session_idle_minutes' => ['type' => 'int', 'default' => self::DEFAULT_SESSION_IDLE_MINUTES, 'min' => 1, 'max' => self::MAX_SESSION_IDLE_MINUTES, 'locked' => false],
    ];

    /** @return int|bool the secure default of a setting */
    public static function defaultFor(string $key): int|bool
    {
        return self::definition($key)['default'];
    }

    public static function isLocked(string $key): bool
    {
        return self::definition($key)['locked'];
    }

    /**
     * The value a setting actually takes: the secure default when nothing (or a value of the wrong type) is stored, and a stored value
     * that is outside the floor/ceiling is pulled back to the floor/ceiling. Locked settings are always their default. Never throws for
     * a known key; use {@see validate()} to reject a request instead.
     */
    public static function effective(string $key, mixed $stored): int|bool
    {
        $def = self::definition($key);
        if ($def['locked']) {
            return $def['default'];
        }
        if ($def['type'] === 'bool') {
            return is_bool($stored) ? $stored : $def['default'];
        }
        if (!is_int($stored)) {
            return $def['default'];
        }

        return max($def['min'] ?? \PHP_INT_MIN, min($def['max'] ?? \PHP_INT_MAX, $stored));
    }

    /**
     * Reject a requested value instead of clamping it.
     *
     * @throws BaselineViolation locked setting, wrong type, below the floor or above the ceiling
     */
    public static function validate(string $key, mixed $requested): int|bool
    {
        $def = self::definition($key);
        if ($def['locked']) {
            if ($requested === $def['default']) {
                return $def['default'];
            }
            throw new BaselineViolation("Baseline setting {$key} is locked on and cannot be changed.");
        }
        if ($def['type'] === 'bool') {
            if (!is_bool($requested)) {
                throw new BaselineViolation("Baseline setting {$key} must be true or false.");
            }

            return $requested;
        }
        if (!is_int($requested)) {
            throw new BaselineViolation("Baseline setting {$key} must be a whole number.");
        }
        if (isset($def['min']) && $requested < $def['min']) {
            throw new BaselineViolation("Baseline setting {$key} cannot be lower than {$def['min']}.");
        }
        if (isset($def['max']) && $requested > $def['max']) {
            throw new BaselineViolation("Baseline setting {$key} cannot be higher than {$def['max']}.");
        }

        return $requested;
    }

    /** Audit retention in days for a stored setting: default 365, never below 90. */
    public static function auditRetentionDays(?int $configured): int
    {
        return (int) self::effective('audit_retention_days', $configured);
    }

    /**
     * Whether MFA is required for a person. Administrators always; everyone else unless the (per client) setting explicitly turned it off.
     */
    public static function mfaRequired(bool $isAdministrator, ?bool $configuredForUsers): bool
    {
        if ($isAdministrator) {
            return true;
        }

        return (bool) self::effective('mfa_required_users', $configuredForUsers);
    }

    /** Whether a candidate password meets the length rule (counted in characters, not bytes; no composition rules, no upper bound below 64). */
    public static function passwordLengthOk(#[\SensitiveParameter] string $password, ?int $configuredMin = null): bool
    {
        $min = (int) self::effective('password_min_length', $configuredMin);
        $len = mb_strlen($password, 'UTF-8');

        return $len >= $min;
    }

    /**
     * Cookie attributes for a session or CSRF cookie. Secure and HttpOnly are always true; SameSite is Lax unless Strict is asked for and
     * never None; the path is `/` and no domain is set, which is what the __Host- prefix requires.
     *
     * @param array{samesite?:string,lifetime?:int} $requested
     * @return array{secure:true,httponly:true,samesite:string,path:string,lifetime:int}
     */
    public static function cookieAttributes(array $requested = []): array
    {
        $same = strtolower((string) ($requested['samesite'] ?? ''));
        $lifetime = (int) ($requested['lifetime'] ?? 0);

        return [
            'secure' => true,
            'httponly' => true,
            'samesite' => $same === 'strict' ? 'Strict' : self::DEFAULT_COOKIE_SAMESITE,
            'path' => '/',
            'lifetime' => max(0, min($lifetime, self::MAX_SESSION_IDLE_MINUTES * 60)),
        ];
    }

    /** The __Host- prefixed name for a session cookie (browser-enforced Secure, Path=/, no Domain). */
    public static function hostCookieName(string $name): string
    {
        return str_starts_with($name, '__Host-') ? $name : '__Host-' . $name;
    }

    /**
     * Why the PostgreSQL connection is below the baseline, or null when it meets it: a unix socket, a loopback address, or TLS with
     * certificate verification (verify-ca / verify-full). Anything else sends device data across a network in a downgradeable or
     * unauthenticated channel (CORE-09).
     */
    public static function postgresTransportProblem(PostgresConfig $config): ?string
    {
        $host = $config->host;
        if (str_starts_with($host, '/') || $host === '127.0.0.1' || $host === '::1' || strtolower($host) === 'localhost') {
            return null;
        }
        if (in_array($config->sslMode, ['verify-ca', 'verify-full'], true)) {
            return null;
        }

        return "PostgreSQL host {$host} is remote and sslmode is '{$config->sslMode}': use a unix socket, or sslmode verify-full with a CA certificate.";
    }

    /** @return array{type:'int'|'bool', default:int|bool, min?:int, max?:int, locked:bool} */
    private static function definition(string $key): array
    {
        return self::SETTINGS[$key] ?? throw new \InvalidArgumentException("Unknown baseline setting {$key}.");
    }
}
