<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Webhooks\EventCatalog;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/**
 * A webhooks "table" in memory (secret stored obfuscated, events as a comma list like both editions) with an optional
 * flaw: includes_disabled, includes_deleted, prefix_match, no_patterns, greedy_star, encrypted_secret, find_disabled,
 * find_deleted, like_wildcards, keyed_list.
 */
final class InMemoryWebhookSubscriptions implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface
{
    /** @var array<int,array{url:string,secret:string,events:string,enabled:bool,deleted:bool}> */
    private array $rows = [];
    private int $next = 100;

    public function __construct(private ?string $flaw = null)
    {
    }

    /** @param list<string> $events */
    public function store(string $url, string $secret, array $events, bool $enabled): int
    {
        $this->rows[$this->next] = ['url' => $url, 'secret' => 'enc:' . base64_encode($secret), 'events' => implode(', ', $events), 'enabled' => $enabled, 'deleted' => false];

        return $this->next++;
    }

    public function delete(int $id): void
    {
        if ($this->flaw === 'includes_deleted') {
            $this->rows[$id]['deleted'] = true;
            $this->rows[$id]['enabled'] = true;

            return;
        }
        unset($this->rows[$id]);
    }

    public function forEvent(string $eventType): array
    {
        $out = [];
        foreach ($this->rows as $id => $r) {
            if ((!$r['enabled'] && $this->flaw !== 'includes_disabled')) {
                continue;
            }
            if ($r['deleted'] && $this->flaw !== 'includes_deleted') {
                continue;
            }
            if ($this->matches($r['events'], $eventType)) {
                $out[$id] = $this->subscription($id, $r);
            }
        }

        return $this->flaw === 'keyed_list' ? $out : array_values($out);
    }

    public function find(int $webhookId): ?WebhookSubscription
    {
        $r = $this->rows[$webhookId] ?? null;
        if ($r === null) {
            return null;
        }
        if (!$r['enabled'] && $this->flaw !== 'find_disabled') {
            return null;
        }

        return $this->subscription($webhookId, $r);
    }

    /** @param array{url:string,secret:string,events:string,enabled:bool,deleted:bool} $r */
    private function subscription(int $id, array $r): WebhookSubscription
    {
        $secret = $this->flaw === 'encrypted_secret' ? $r['secret'] : (string) base64_decode(substr($r['secret'], 4));

        return new WebhookSubscription($id, $r['url'], $secret);
    }

    private function matches(string $stored, string $event): bool
    {
        foreach (explode(',', $stored) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if ($this->flaw === 'prefix_match' && $event !== '' && str_starts_with($token, $event)) {
                return true;
            }
            if ($this->flaw === 'like_wildcards') {
                $like = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($event, '/')) . '$/';
                // the QUERY is used as a LIKE pattern against the stored token
                if (@preg_match($like, $token) === 1 && $event !== '') {
                    return true;
                }
            }
            if ($token === $event) {
                return true;
            }
            if ($this->flaw === 'no_patterns' || !EventCatalog::isPattern($token)) {
                continue;
            }
            $prefix = $this->flaw === 'greedy_star' ? rtrim($token, '.*') : rtrim($token, '*');
            if ($token === '*' || ($prefix !== '' && str_starts_with($event, $prefix))) {
                return true;
            }
        }

        return false;
    }
}
