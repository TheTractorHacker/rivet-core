<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

use Psr\Log\LoggerInterface;

/**
 * Finds the key ring: a key file first, then the environment. A file that exists but is bad is an error, never silently replaced by the
 * environment (that would hide a broken deployment).
 *
 * Environment (read through getenv, or the closure you inject):
 *   RIVETCORE_KEYRING   the JSON of a key file
 *   RIVETCORE_KEK       one key, 64 hex characters, loaded as kid RIVETCORE_KEK_ID (default "k1")
 *
 * @api
 */
final class KeyRingLoader
{
    /** @var \Closure(string): ?string */
    private \Closure $env;

    /** @param (\Closure(string): ?string)|null $env */
    public function __construct(
        private ?LoggerInterface $logger = null,
        private bool $strict = false,
        private ?string $webRoot = null,
        ?\Closure $env = null,
    ) {
        $this->env = $env ?? static function (string $name): ?string {
            $v = getenv($name);

            return is_string($v) && $v !== '' ? $v : null;
        };
    }

    /**
     * @param string|null $path key file; null skips the file
     * @param bool $required true: a missing key source throws {@see KeyUnavailable}; false: an empty ring is returned
     * @throws KeyUnavailable
     * @throws InvalidKeyMaterial
     */
    public function load(?string $path, bool $required = true): KeyRingLoadResult
    {
        if ($path !== null && is_file($path)) {
            return $this->fromFile($path);
        }
        $json = ($this->env)('RIVETCORE_KEYRING');
        if ($json !== null) {
            return new KeyRingLoadResult(KeyFile::ringFromJson($json), 'env', ['The key ring comes from the environment; prefer a root-owned key file.']);
        }
        $hex = ($this->env)('RIVETCORE_KEK');
        if ($hex !== null) {
            $kid = ($this->env)('RIVETCORE_KEK_ID') ?? 'k1';

            return new KeyRingLoadResult(KeyRing::single(KeyFile::decodeKey($hex), $kid), 'env', ['The key comes from the environment; prefer a root-owned key file.']);
        }
        if ($required) {
            throw new KeyUnavailable('No key ring found: no key file at the configured path and no RIVETCORE_KEYRING / RIVETCORE_KEK in the environment.');
        }

        return new KeyRingLoadResult(KeyRing::empty(), 'none', ['No encryption key is configured.']);
    }

    /** @throws KeyUnavailable|InvalidKeyMaterial */
    public function fromFile(string $path): KeyRingLoadResult
    {
        $report = KeyFile::inspect($path, $this->webRoot);
        if ($report['errors'] !== []) {
            throw new KeyUnavailable($report['errors'][0]);
        }
        if ($this->strict && $report['warnings'] !== []) {
            throw new KeyUnavailable($report['warnings'][0]);
        }
        foreach ($report['warnings'] as $w) {
            $this->logger?->warning('Key file: ' . $w, ['file' => basename($path)]);
        }
        $ring = str_ends_with(strtolower($path), '.php')
            ? KeyFile::ringFromArray($this->includeArray($path))
            : KeyFile::ringFromJson((string) @file_get_contents($path));

        return new KeyRingLoadResult($ring, 'file', $report['warnings']);
    }

    /** @return array<mixed> */
    private function includeArray(string $path): array
    {
        $data = (static fn (string $f): mixed => include $f)($path);
        if (!is_array($data)) {
            throw new InvalidKeyMaterial('The PHP key file must return an array.');
        }

        return $data;
    }
}
