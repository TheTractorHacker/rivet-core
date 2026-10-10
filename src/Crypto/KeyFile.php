<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * The on-disk key ring: JSON (`*.json`, preferred) or a PHP file that returns the same array (`*.php`), kept outside the web root,
 * owned by root and readable by the PHP user only (mode 0640 root:www-data).
 *
 *     {"version":1,"active":"k2","keys":{"k1":{"key":"<64 hex>","created":"2026-10-01T00:00:00+00:00"},"k2":"<64 hex>"}}
 *
 * A key is 64 hex characters or `base64:<44 chars>`; the value is either the string or an object with `key` and optional `created`.
 *
 * @api
 */
final class KeyFile
{
    /** @return array<string,mixed> */
    public static function decodeArray(mixed $data): array
    {
        if (!is_array($data) || !isset($data['keys']) || !is_array($data['keys'])) {
            throw new InvalidKeyMaterial('The key file must contain a "keys" object.');
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidKeyMaterial
     */
    public static function ringFromArray(#[\SensitiveParameter] array $data): KeyRing
    {
        $data = self::decodeArray($data);
        $keys = [];
        $created = [];
        foreach ($data['keys'] as $kid => $entry) {
            $encoded = is_array($entry) ? ($entry['key'] ?? null) : $entry;
            if (!is_string($encoded)) {
                throw new InvalidKeyMaterial('Key "' . $kid . '" is not a string.');
            }
            $keys[(string) $kid] = self::decodeKey($encoded);
            if (is_array($entry) && isset($entry['created']) && is_string($entry['created'])) {
                $created[(string) $kid] = $entry['created'];
            }
        }
        $active = $data['active'] ?? null;
        if ($active !== null && !is_string($active)) {
            throw new InvalidKeyMaterial('"active" must be a key id.');
        }

        return KeyRing::fromKeys($keys, $active, $created);
    }

    /** @throws InvalidKeyMaterial */
    public static function ringFromJson(#[\SensitiveParameter] string $json): KeyRing
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidKeyMaterial('The key ring is not valid JSON.');
        }

        return self::ringFromArray(self::decodeArray($data));
    }

    /** Hex (64) or "base64:..." to 32 raw bytes. @throws InvalidKeyMaterial */
    public static function decodeKey(#[\SensitiveParameter] string $encoded): string
    {
        $encoded = trim($encoded);
        if (preg_match('/^[0-9a-fA-F]{64}$/', $encoded) === 1) {
            return (string) hex2bin($encoded);
        }
        if (str_starts_with($encoded, 'base64:')) {
            $raw = base64_decode(substr($encoded, 7), true);
            if ($raw !== false) {
                return $raw;
            }
        }
        throw new InvalidKeyMaterial('A key must be 64 hex characters or "base64:" followed by 32 bytes.');
    }

    /** The JSON text of a ring (contains key material: write it only with {@see write()}). */
    public static function encode(KeyRing $ring): string
    {
        $keys = [];
        foreach ($ring->kids() as $kid) {
            $entry = ['key' => bin2hex($ring->key($kid))];
            if ($ring->createdAt($kid) !== null) {
                $entry['created'] = $ring->createdAt($kid);
            }
            $keys[$kid] = $entry;
        }

        return json_encode(['version' => 1, 'active' => $ring->activeKid(), 'keys' => $keys], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Writes the ring atomically (temp file in the same directory, mode applied before the key bytes are written, then rename). Refuses
     * to replace an existing file unless $replace, because overwriting a key file loses every value sealed under the old keys.
     *
     * @throws KeyUnavailable
     */
    public static function write(string $path, KeyRing $ring, bool $replace = false, int $mode = 0640): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new KeyUnavailable('The key file directory does not exist or is not writable.');
        }
        if (file_exists($path) && !$replace) {
            throw new KeyUnavailable('The key file already exists; refusing to overwrite it.');
        }
        $tmp = tempnam($dir, '.keyring');
        if ($tmp === false) {
            throw new KeyUnavailable('Could not create a temporary key file.');
        }
        try {
            if (!chmod($tmp, $mode) || file_put_contents($tmp, self::encode($ring), LOCK_EX) === false || !rename($tmp, $path)) {
                throw new KeyUnavailable('Could not write the key file.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Permission findings for a key file. Errors make the loader refuse; warnings are reported.
     *
     * @return array{errors:list<string>,warnings:list<string>,mode:int}
     */
    public static function inspect(string $path, ?string $webRoot = null): array
    {
        clearstatcache(true, $path); // the answer must reflect the file as it is now, not a cached stat
        $errors = [];
        $warnings = [];
        $perms = @fileperms($path);
        if ($perms === false) {
            return ['errors' => ['The key file cannot be examined.'], 'warnings' => [], 'mode' => 0];
        }
        $mode = $perms & 0777;
        if (DIRECTORY_SEPARATOR === '/') {
            if (($mode & 0002) !== 0) {
                $errors[] = 'The key file is world-writable (anyone could replace the keys).';
            }
            if (($mode & 0004) !== 0) {
                $warnings[] = 'The key file is world-readable; use mode 0640 root:www-data.';
            }
            if (($mode & 0020) !== 0) {
                $warnings[] = 'The key file is group-writable; the PHP user should only read it.';
            }
            $owner = @fileowner($path);
            if ($owner !== false && $owner !== 0 && function_exists('posix_geteuid') && $owner !== posix_geteuid()) {
                $warnings[] = 'The key file is owned by neither root nor the running user.';
            }
        }
        if ($webRoot !== null) {
            $real = realpath($path);
            $root = realpath($webRoot);
            if ($real !== false && $root !== false && str_starts_with($real, rtrim($root, '/') . '/')) {
                $warnings[] = 'The key file is inside the web root.';
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'mode' => $mode];
    }
}
