<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Testing\WebhookSubscriptionsConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\InMemoryWebhookSubscriptions;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/** The kit against an in-memory subscription store (and the harness target for the webhook mutants). */
final class WebhookKitTest extends WebhookSubscriptionsConformanceTestCase
{
    use Flaw;

    private ?InMemoryWebhookSubscriptions $store = null;

    private function store(): InMemoryWebhookSubscriptions
    {
        return $this->store ??= new InMemoryWebhookSubscriptions(self::$flaw);
    }

    protected function subscriptions(): WebhookSubscriptionsInterface
    {
        return $this->store();
    }

    protected function storeSubscription(string $url, string $secret, array $events, bool $enabled): int
    {
        return $this->store()->store($url, $secret, $events, $enabled);
    }

    protected function deleteSubscription(int $webhookId): void
    {
        $this->store()->delete($webhookId);
    }
}
