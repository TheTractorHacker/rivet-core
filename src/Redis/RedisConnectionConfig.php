<?php

declare(strict_types=1);

namespace RivetCore\Redis;

/**
 * One Redis connection described as plain values: host, port, database, optional password and ACL username, and optional
 * TLS. Editions resolve these from their own sources (environment, settings table), build a config, and let Core turn it
 * into Predis parameters, validate it and test it. The password is never part of var_dump()/print_r() output or error text.
 *
 * @api
 */
final class RedisConnectionConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port = 6379,
        public readonly int $db = 0,
        #[\SensitiveParameter]
        public readonly ?string $password = null,
        public readonly ?string $username = null,
        public readonly bool $tls = false,
        public readonly bool $verifyPeer = true,
        public readonly ?string $caFile = null,
        public readonly ?string $certFile = null,
        public readonly ?string $keyFile = null,
    ) {
    }

    /**
     * Accepts the edition settings shape (host, port, db, password) plus optional username, tls (bool), tls_verify (bool),
     * tls_ca_file, tls_cert_file, tls_key_file. Empty strings count as "not set".
     *
     * @param array<string,mixed> $p
     */
    public static function fromArray(array $p): self
    {
        $str = static fn (string $k): ?string => isset($p[$k]) && is_scalar($p[$k]) && (string) $p[$k] !== '' ? (string) $p[$k] : null;

        return new self(
            (string) ($p['host'] ?? ''),
            (int) ($p['port'] ?? 6379),
            (int) ($p['db'] ?? $p['database'] ?? 0),
            $str('password'),
            $str('username'),
            filter_var($p['tls'] ?? false, FILTER_VALIDATE_BOOLEAN),
            filter_var($p['tls_verify'] ?? true, FILTER_VALIDATE_BOOLEAN),
            $str('tls_ca_file'),
            $str('tls_cert_file'),
            $str('tls_key_file'),
        );
    }

    /**
     * @param bool $checkFiles also require the configured certificate files to exist and be readable (used when actually connecting)
     * @return ?string an error message, or null when the values are acceptable
     */
    public function validate(bool $checkFiles = false): ?string
    {
        if ($this->host === '' || strlen($this->host) > 253 || !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$|^\[?[0-9A-Fa-f:]+\]?$/', $this->host)) {
            return 'Enter a host name or IP address.';
        }
        if ($this->port < 1 || $this->port > 65535) {
            return 'The port must be between 1 and 65535.';
        }
        if ($this->db < 0 || $this->db > 15) {
            return 'The database number must be between 0 and 15.';
        }
        if ($this->password !== null && (strlen($this->password) > 500 || preg_match('/[\x00\r\n]/', $this->password))) {
            return 'The password is too long or contains a line break.';
        }
        if ($this->username !== null) {
            if (strlen($this->username) > 128 || !preg_match('/^[A-Za-z0-9._@:-]+$/', $this->username)) {
                return 'The username may use letters, digits and . _ @ : - only (up to 128 characters).';
            }
            if ($this->password === null) {
                return 'A username needs a password.';
            }
        }
        if (!$this->tls && ($this->caFile !== null || $this->certFile !== null || $this->keyFile !== null)) {
            return 'Certificate files only apply when TLS is turned on.';
        }
        if ($this->keyFile !== null && $this->certFile === null) {
            return 'A client key needs a client certificate.';
        }
        foreach (['CA file' => $this->caFile, 'client certificate' => $this->certFile, 'client key' => $this->keyFile] as $label => $path) {
            if ($path === null) {
                continue;
            }
            if (strlen($path) > 1024 || preg_match('/[\x00\r\n]/', $path) || str_contains($path, '://')) {
                return "The {$label} path is not valid.";
            }
            if ($checkFiles && (!is_file($path) || !is_readable($path))) {
                return "The {$label} cannot be read at the path given.";
            }
        }

        return null;
    }

    /**
     * Parameters for `new Predis\Client(...)`.
     *
     * @return array<string,mixed>
     */
    public function toPredisParameters(float $timeout = 1.0): array
    {
        $parameters = [
            'scheme' => $this->tls ? 'tls' : 'tcp',
            'host' => trim($this->host, '[]'),
            'port' => $this->port,
            'database' => $this->db,
            'timeout' => $timeout,
        ];
        if ($this->password !== null) {
            $parameters['password'] = $this->password;
            if ($this->username !== null) {
                $parameters['username'] = $this->username;
            }
        }
        if ($this->tls) {
            $ssl = ['verify_peer' => $this->verifyPeer, 'verify_peer_name' => $this->verifyPeer];
            if ($this->caFile !== null) {
                $ssl['cafile'] = $this->caFile;
            }
            if ($this->certFile !== null) {
                $ssl['local_cert'] = $this->certFile;
            }
            if ($this->keyFile !== null) {
                $ssl['local_pk'] = $this->keyFile;
            }
            $parameters['ssl'] = $ssl;
        }

        return $parameters;
    }

    /** Remove the password from any text (an error message, a log line) before it is shown. */
    public function redact(string $text): string
    {
        return $this->password === null || $this->password === '' ? $text : str_replace($this->password, '***', $text);
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host, 'port' => $this->port, 'db' => $this->db,
            'password' => $this->password === null ? null : '***', 'username' => $this->username,
            'tls' => $this->tls, 'verifyPeer' => $this->verifyPeer,
            'caFile' => $this->caFile, 'certFile' => $this->certFile, 'keyFile' => $this->keyFile,
        ];
    }
}
