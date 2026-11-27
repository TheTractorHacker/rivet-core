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
 * Two modes, which may be combined:
 *  - Shared secrets (the first argument, single-secret mode): every holder is the same {@see InboundSender::shared()} sender and is trusted for every
 *    monitor and every client. Unchanged from rc.78.
 *  - Per-sender secrets (the optional second argument, red team 2026-11-21 Chain A): each sender has its own secret(s) and, optionally, the list of clients it
 *    may report for; {@see identify()} answers that {@see InboundSender::named()} sender, whose incidents are its own (see {@see InboundSender}).
 * A secret configured for two different senders (or as a shared secret and a sender's) is ambiguous and authenticates nobody.
 *
 * @api
 */
final class InboundAuthenticator
{
    public const MIN_SECRET_LENGTH = 16;
    public const HEADER = 'X-Webhook-Secret';

    /** @var list<array{0:string,1:InboundSender}> sha256 digest (raw) of each accepted secret and the sender it identifies */
    private array $digests = [];

    /**
     * @param list<string|null> $secrets shared secrets: whoever holds one is trusted for every monitor and every client
     * @param array<string,array{secrets:list<string|null>,clients?:array<mixed>|null}> $senders optional per-sender secrets: sender id ({@see InboundSender::ID_PATTERN})
     *        => its secrets and the clients it may report for (absent or null = any client, its own incidents only)
     * @throws \InvalidArgumentException for a malformed sender id or client list (a configuration error the edition's settings page should have refused)
     */
    public function __construct(array $secrets, array $senders = [])
    {
        $shared = InboundSender::shared();
        foreach ($secrets as $s) {
            $this->add($s, $shared);
        }
        foreach ($senders as $id => $cfg) {
            $sender = InboundSender::named((string) $id, $cfg['clients'] ?? null);
            foreach ($cfg['secrets'] as $s) {
                $this->add($s, $sender);
            }
        }
    }

    private function add(mixed $secret, InboundSender $sender): void
    {
        if (is_string($secret) && strlen($secret) >= self::MIN_SECRET_LENGTH) {
            $this->digests[] = [hash('sha256', $secret, true), $sender];
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
        return $this->identify($headers) !== null;
    }

    /**
     * The sender the presented secret belongs to, or null (wrong, missing or unconfigured secret, or a secret that matches two different senders).
     *
     * @param array<string,string|list<string>> $headers any header-name case
     */
    public function identify(array $headers): ?InboundSender
    {
        $presented = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            $v = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            if ($lower === 'x-webhook-secret') {
                $presented[] = trim($v);
            } elseif ($lower === 'authorization' && preg_match('/^Bearer[ \t]+(\S+)[ \t]*$/iD', $v, $m) === 1) {
                $presented[] = $m[1];
            }
        }
        /** @var array<string,InboundSender> $matched sender id => sender */
        $matched = [];
        foreach ($presented as $p) {
            $d = hash('sha256', $p, true);
            foreach ($this->digests as [$known, $sender]) {
                if (hash_equals($known, $d)) {   // no early exit: every candidate is compared against every secret
                    $matched[$sender->id] = $sender;
                }
            }
        }

        return count($matched) === 1 ? array_values($matched)[0] : null;
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        $senders = [];
        foreach ($this->digests as [, $s]) {
            if (!$s->isShared()) {
                $senders[$s->id] = true;
            }
        }

        return ['configured' => $this->isConfigured(), 'senders' => array_keys($senders)];
    }
}
