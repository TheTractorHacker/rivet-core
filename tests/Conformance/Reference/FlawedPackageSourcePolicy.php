<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Rmm\Contracts\PackageSourcePolicyInterface;
use RivetCore\Rmm\Deploy\AllowedHostsPolicy;

/**
 * Core's real policy with ONE deliberate flaw each (the harness target of the package source policy mutants). Null = the correct policy. Every flaw is a mistake a
 * hand-written host check really makes: a scheme left out, a prefix or suffix test, parse_url trusted, whitespace trimmed, user information ignored.
 */
final class FlawedPackageSourcePolicy implements PackageSourcePolicyInterface
{
    public const HOST = 'downloads.example.com';
    private int $calls = 0;
    private readonly AllowedHostsPolicy $real;

    public function __construct(private readonly ?string $flaw = null)
    {
        $this->real = new AllowedHostsPolicy(static fn (): array => [self::HOST]);
    }

    public function allows(string $url, int $clientId): bool
    {
        ++$this->calls;
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        switch ($this->flaw) {
            case 'allows_http':
                return $this->real->allows(preg_replace('#^http://#', 'https://', $url) ?? $url, $clientId);
            case 'allows_userinfo':
                return $this->real->allows(preg_replace('#^(https://)[^/@]*@#', '$1', $url) ?? $url, $clientId);
            case 'suffix_match':
                return str_starts_with($url, 'https://') && str_ends_with(strtolower($host), self::HOST);
            case 'prefix_match':
                return str_starts_with($url, 'https://') && str_starts_with(strtolower($host), self::HOST);
            case 'substring':
                return str_starts_with($url, 'https://') && str_contains(strtolower($url), self::HOST);
            case 'allows_ip':
                return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || $this->real->allows($url, $clientId);
            case 'allows_numeric_host':
                return str_starts_with($url, 'https://') && preg_match('/^(0x[0-9a-f]+|\d+)(\.|$)/i', $host) === 1 || $this->real->allows($url, $clientId);
            case 'case_sensitive':
                return $url === strtolower($url) || preg_match('#^https://' . preg_quote(self::HOST, '#') . '(/|$)#', $url) === 1 ? $this->real->allows($url, $clientId) : false;
            case 'allows_port':
                return $this->real->allows(preg_replace('#^(https://[^/:?]+):\d*#', '$1', $url) ?? $url, $clientId);
            case 'trims_input':
                return $this->real->allows(trim($url), $clientId);
            case 'strips_controls':
                return $this->real->allows(preg_replace('/[\x00-\x1f\x7f]/', '', $url) ?? $url, $clientId);
            case 'allows_fragment':
                return $this->real->allows(preg_replace('/#.*$/s', '', $url) ?? $url, $clientId);
            case 'backslash_as_slash':
                return $this->real->allows(str_replace('\\', '/', $url), $clientId);
            case 'throws_on_garbage':
                if ($url === '' || strlen($url) > 2000 || !mb_check_encoding($url, 'UTF-8')) {
                    throw new \InvalidArgumentException('cannot parse');
                }

                return $this->real->allows($url, $clientId);
            case 'flips':
                return $this->calls % 3 === 0 ? !$this->real->allows($url, $clientId) : $this->real->allows($url, $clientId);
            case 'client_elevates':
                return $clientId > 1000 || $clientId < 0 ? str_starts_with($url, 'http') : $this->real->allows($url, $clientId);
            case 'returns_int':
                return $this->real->allows($url, $clientId) ? 1 : 0;   // @phpstan-ignore-line deliberately not a bool
            case 'allows_unlisted_nonascii':
                return $this->real->allows($url, $clientId) || preg_match('#^https://[^/]*[^\x00-\x7f]#u', $url) === 1;
        }

        return $this->real->allows($url, $clientId);
    }
}
