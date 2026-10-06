<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Tests\Conformance\Reference\InMemoryWebhookSubscriptions;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

final class InMemoryWebhookSubscriptionsConformanceTest extends WebhookSubscriptionsConformanceTestCase
{
    private InMemoryWebhookSubscriptions $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryWebhookSubscriptions();
    }

    protected function givenEndpoint(int $id, string $eventType, string $url, string $secret): void
    {
        $this->store->add($id, $eventType, $url, $secret);
    }

    protected function givenDisabledEndpoint(int $id, string $eventType, string $url, string $secret): void
    {
        $this->store->add($id, $eventType, $url, $secret, false);
    }

    protected function adapter(): WebhookSubscriptionsInterface
    {
        return $this->store;
    }
}
