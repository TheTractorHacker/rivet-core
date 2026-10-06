<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * Builds and validates the OUTGOING authentication of a webhook destination (what a receiver such as n8n, Gotify or ntfy
 * expects), separately from our HMAC signature headers, which are always sent.
 *
 * Config: {mode: none|bearer|basic|header|hmac, token, username, password, header_name, header_value}. "hmac" means the
 * signature headers only (no extra header). Secrets are never echoed: use redact() for display and audit.
 *
 * @api
 */
final class Authentication
{
    public const MODES = ['none', 'bearer', 'basic', 'header', 'hmac'];

    public const MAX_TOKEN = 2048;
    public const MAX_USERNAME = 256;
    public const MAX_PASSWORD = 1024;
    public const MAX_HEADER_NAME = 64;
    public const MAX_HEADER_VALUE = 2048;

    /** Headers a destination config may never set (framing, hop-by-hop, set by the formatter or by us). */
    private const FORBIDDEN = [
        'host', 'content-length', 'content-type', 'content-encoding', 'transfer-encoding', 'connection', 'keep-alive',
        'proxy-authenticate', 'proxy-authorization', 'proxy-connection', 'te', 'trailer', 'upgrade', 'expect', 'http2-settings',
    ];

    /**
     * @param array<string,mixed> $config
     * @return array<string,string>
     */
    public static function headers(array $config): array
    {
        $errors = self::validate($config);
        if ($errors !== []) {
            throw new \InvalidArgumentException('Invalid webhook authentication: ' . $errors[0]);
        }
        $mode = self::mode($config);

        return match ($mode) {
            'bearer' => ['Authorization' => 'Bearer ' . self::str($config, 'token')],
            'basic' => ['Authorization' => 'Basic ' . base64_encode(self::str($config, 'username') . ':' . self::str($config, 'password'))],
            'header' => [self::str($config, 'header_name') => self::str($config, 'header_value')],
            default => [],
        };
    }

    /**
     * @param array<string,mixed> $config
     * @return list<string> human-readable problems (empty = valid)
     */
    public static function validate(array $config): array
    {
        $errors = [];
        $mode = self::mode($config);
        if (!in_array($mode, self::MODES, true)) {
            return ['Authentication mode must be one of: ' . implode(', ', self::MODES) . '.'];
        }
        $bad = static fn (string $v): bool => preg_match('/[\x00-\x1F\x7F]/', $v) === 1;

        if ($mode === 'bearer') {
            $t = self::str($config, 'token');
            if ($t === '') {
                $errors[] = 'A bearer token is required.';
            } elseif (strlen($t) > self::MAX_TOKEN) {
                $errors[] = 'The bearer token is too long (max ' . self::MAX_TOKEN . ' characters).';
            } elseif ($bad($t) || preg_match('/\s/', $t) === 1) {
                $errors[] = 'The bearer token must not contain spaces or control characters.';
            }
        } elseif ($mode === 'basic') {
            $u = self::str($config, 'username');
            $p = self::str($config, 'password');
            if ($u === '') {
                $errors[] = 'A username is required for basic authentication.';
            } elseif (strlen($u) > self::MAX_USERNAME) {
                $errors[] = 'The username is too long (max ' . self::MAX_USERNAME . ' characters).';
            } elseif (str_contains($u, ':') || $bad($u)) {
                $errors[] = 'The username must not contain a colon or control characters.';
            }
            if (strlen($p) > self::MAX_PASSWORD) {
                $errors[] = 'The password is too long (max ' . self::MAX_PASSWORD . ' characters).';
            } elseif ($bad($p)) {
                $errors[] = 'The password must not contain control characters.';
            }
        } elseif ($mode === 'header') {
            $n = self::str($config, 'header_name');
            $v = self::str($config, 'header_value');
            if ($n === '') {
                $errors[] = 'A header name is required.';
            } elseif (strlen($n) > self::MAX_HEADER_NAME) {
                $errors[] = 'The header name is too long (max ' . self::MAX_HEADER_NAME . ' characters).';
            } elseif (!self::isValidHeaderName($n)) {
                $errors[] = 'The header name may only contain letters, digits and - _ . ! # $ % & \' * + ^ ` | ~.';
            } elseif (self::isForbiddenHeaderName($n)) {
                $errors[] = 'The header name "' . $n . '" is reserved and cannot be set.';
            }
            if ($v === '') {
                $errors[] = 'A header value is required.';
            } elseif (strlen($v) > self::MAX_HEADER_VALUE) {
                $errors[] = 'The header value is too long (max ' . self::MAX_HEADER_VALUE . ' characters).';
            } elseif ($bad($v)) {
                $errors[] = 'The header value must not contain line breaks or control characters.';
            }
        }

        return $errors;
    }

    /**
     * A copy that is safe to display or log: secrets are masked, identifying fields are kept.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function redact(array $config): array
    {
        $mask = static fn (string $v): string => $v === '' ? '' : '********';

        return [
            'mode' => self::mode($config),
            'token' => $mask(self::str($config, 'token')),
            'username' => self::str($config, 'username'),
            'password' => $mask(self::str($config, 'password')),
            'header_name' => self::str($config, 'header_name'),
            'header_value' => $mask(self::str($config, 'header_value')),
        ];
    }

    /** RFC 7230 token. */
    public static function isValidHeaderName(string $name): bool
    {
        return $name !== '' && preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D', $name) === 1;
    }

    /** Framing / hop-by-hop headers and anything that looks like one of our own signature or timestamp headers. */
    public static function isForbiddenHeaderName(string $name): bool
    {
        $n = strtolower($name);

        return in_array($n, self::FORBIDDEN, true)
            || str_starts_with($n, 'x-rivet-')
            || str_ends_with($n, '-signature')
            || str_contains($n, 'signature-v');
    }

    /** @param array<string,mixed> $config */
    private static function mode(array $config): string
    {
        $m = $config['mode'] ?? 'none';

        return is_string($m) && $m !== '' ? strtolower($m) : 'none';
    }

    /** @param array<string,mixed> $config */
    private static function str(array $config, string $key): string
    {
        $v = $config[$key] ?? '';

        return is_scalar($v) ? (string) $v : '';
    }
}
