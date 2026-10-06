<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/**
 * Behaviour every WebhookSubscriptionsInterface adapter (and its optional lookup companion) must have.
 *
 * Implement the three arrange hooks against the edition's own storage; adapter() is called AFTER the hooks, so it may
 * read what they wrote.
 */
abstract class WebhookSubscriptionsConformanceTestCase extends TestCase
{
    /** Store an enabled endpoint subscribed to $eventType. */
    abstract protected function givenEndpoint(int $id, string $eventType, string $url, string $secret): void;

    /** Store a DISABLED endpoint subscribed to $eventType. */
    abstract protected function givenDisabledEndpoint(int $id, string $eventType, string $url, string $secret): void;

    abstract protected function adapter(): WebhookSubscriptionsInterface;

    public function testReturnsOnlyEnabledEndpointsForTheEvent(): void
    {
        $this->givenEndpoint(9001, 'ticket.created', 'https://example.test/a', 'secret-a');
        $this->givenEndpoint(9002, 'client.created', 'https://example.test/b', 'secret-b');
        $this->givenDisabledEndpoint(9003, 'ticket.created', 'https://example.test/c', 'secret-c');

        $subs = $this->adapter()->forEvent('ticket.created');
        $this->assertContainsOnlyInstancesOf(WebhookSubscription::class, $subs);
        $ids = array_map(static fn (WebhookSubscription $s): int => $s->webhookId, $subs);
        $this->assertContains(9001, $ids);
        $this->assertNotContains(9002, $ids, 'an endpoint for another event must not be returned');
        $this->assertNotContains(9003, $ids, 'a disabled endpoint must not be returned');
    }

    public function testSubscriptionCarriesUrlAndSecretUnchanged(): void
    {
        $this->givenEndpoint(9010, 'ticket.created', 'https://example.test/hook?a=1&b=2', 's3cr3t-with-"quotes"-and-ü');
        foreach ($this->adapter()->forEvent('ticket.created') as $s) {
            if ($s->webhookId === 9010) {
                $this->assertSame('https://example.test/hook?a=1&b=2', $s->url);
                $this->assertSame('s3cr3t-with-"quotes"-and-ü', $s->secret);

                return;
            }
        }
        $this->fail('endpoint 9010 was not returned');
    }

    public function testNoSubscribersIsAnEmptyListNotAnError(): void
    {
        $this->assertSame([], $this->adapter()->forEvent('rivetcore.conformance.nobody-listens'));
    }

    public function testLookupFindsOneEndpointAndReturnsNullForUnknownOrDisabled(): void
    {
        $a = $this->adapter();
        if (!$a instanceof WebhookSubscriptionLookupInterface) {
            $this->markTestSkipped('adapter does not implement the optional lookup companion');
        }
        $this->givenEndpoint(9020, 'ticket.created', 'https://example.test/d', 'secret-d');
        $this->givenDisabledEndpoint(9021, 'ticket.created', 'https://example.test/e', 'secret-e');
        $a = $this->adapter();
        $this->assertInstanceOf(WebhookSubscriptionLookupInterface::class, $a);
        $found = $a->find(9020);
        $this->assertNotNull($found);
        $this->assertSame(9020, $found->webhookId);
        $this->assertNull($a->find(987654321), 'unknown id');
        $this->assertNull($a->find(9021), 'a disabled endpoint is gone as far as a retry is concerned');
    }
}
