<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * Shared-secret check for the inbound endpoint. The secret comes from the header "X-Webhook-Secret" or "Authorization: Bearer <secret>"
 * (Uptime Kuma: Webhook > Additional Headers) and is compared with hash_equals over SHA-256 digests, so neither the value nor its length
 * leaks through timing. Fail closed: no configured secret, an empty one, or one shorter than {@see self::MIN_SECRET_LENGTH} authenticates nobody.
 * Several secrets may be configured at once (rotation: add the new one, switch the sender, remove the old). Secrets are never part of a
 * message, exception or {@see __debugInfo()}.
 *
 * @api
 */
final class InboundAuthenticator
{
    public const MIN_SECRET_LENGTH = 16;
    public const HEADER = 'X-Webhook-Secret';

    /** @var list<string> sha256 digests (raw) of the accepted secrets */
    private array $digests = [];

    /** @param list<string|null> $secrets */
    public function __construct(array $secrets)
    {
        foreach ($secrets as $s) {
            if (is_string($s) && strlen($s) >= self::MIN_SECRET_LENGTH) {
                $this->digests[] = hash('sha256', $s, true);
            }
        }
    }

    /** Whether any usable secret is configured (an admin page can warn when not). */
    public function isConfigured(): bool
    {
        return $this->digests !== [];
    }

    /** @param array<string,string|list<string>> $headers any header-name case */
    public function authenticate(array $headers): bool
    {
        $presented = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            $v = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            if ($lower === 'x-webhook-secret') {
                $presented[] = trim($v);
            } elseif ($lower === 'authorization' && preg_match('/^Bearer[ \t]+(\S+)[ \t]*$/i', $v, $m) === 1) {
                $presented[] = $m[1];
            }
        }
        $ok = false;
        foreach ($presented as $p) {
            $d = hash('sha256', $p, true);
            foreach ($this->digests as $known) {
                if (hash_equals($known, $d)) {   // no early exit: every candidate is compared against every secret
                    $ok = true;
                }
            }
        }

        return $ok;
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['configured' => $this->isConfigured()];
    }
}
