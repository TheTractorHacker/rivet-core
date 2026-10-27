<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/**
 * Conformance kit for {@see WebhookSubscriptionsInterface} (and, when the adapter also implements it,
 * {@see WebhookSubscriptionLookupInterface}).
 *
 * The edition seeds endpoints through its own store ({@see self::seedSubscription()}); the case then asks the adapter who is
 * subscribed. Checks: enabled endpoints for an event are returned with id, URL and DECRYPTED secret; disabled and deleted
 * endpoints are not; near-miss and hostile event names match nothing; stored patterns ("*", "ticket.*") match as documented
 * (opt out with {@see self::supportsEventPatterns()}); find() returns an enabled endpoint and null for unknown, disabled or
 * deleted ones.
 *
 * Run it against a scratch database. The case only inspects the ids it seeded, so unrelated rows do not disturb it, and it
 * deletes what it created.
 *
 * @api
 */
abstract class WebhookSubscriptionsConformanceTestCase extends TestCase
{
    use UntypedValues;

    /** @var list<int> */
    private array $seeded = [];

    /** The adapter under test, reading the same store {@see self::seedSubscription()} writes. */
    abstract protected function subscriptions(): WebhookSubscriptionsInterface;

    /**
     * Store one endpoint the way the edition does (encrypting the secret as it normally would) and return its id.
     *
     * @param list<string> $events event ids and/or patterns the endpoint subscribes to
     */
    abstract protected function storeSubscription(string $url, string $secret, array $events, bool $enabled): int;

    /** Remove the endpoint for good (hard delete or the edition's soft delete). Must not fail for an id that is already gone. */
    abstract protected function deleteSubscription(int $webhookId): void;

    /** Does the edition let users store patterns such as "*" and "ticket.*"? Return false only if it never does. */
    protected function supportsEventPatterns(): bool
    {
        return true;
    }

    /** The lookup side, or null when the adapter does not offer find(). */
    protected function lookup(): ?WebhookSubscriptionLookupInterface
    {
        $s = $this->subscriptions();

        return $s instanceof WebhookSubscriptionLookupInterface ? $s : null;
    }

    /** @param list<string> $events */
    final protected function seedSubscription(array $events, bool $enabled = true, ?string $secret = null): int
    {
        $id = $this->storeSubscription('https://hooks.example.com/' . bin2hex(random_bytes(6)), $secret ?? 'sek_' . bin2hex(random_bytes(8)), $events, $enabled);
        $this->seeded[] = $id;

        return $id;
    }

    protected function tearDown(): void
    {
        foreach ($this->seeded as $id) {
            try {
                $this->deleteSubscription($id);
            } catch (\Throwable) {
                // best effort
            }
        }
        $this->seeded = [];
    }

    /** @return list<int> */
    private function ids(string $event): array
    {
        $list = self::untyped($this->subscriptions()->forEvent($event));
        $this->assertIsArray($list);
        $this->assertTrue(array_is_list($list), 'forEvent() must return a list');
        $ids = [];
        foreach ($list as $s) {
            $this->assertInstanceOf(WebhookSubscription::class, $s);
            $ids[] = $s->webhookId;
        }

        return $ids;
    }

    private function uniqueEvent(string $prefix = 'conf'): string
    {
        return $prefix . bin2hex(random_bytes(3)) . '.created';
    }

    public function testEnabledEndpointsForAnEventAreReturnedWithIdUrlAndPlainSecret(): void
    {
        $a = $this->seedSubscription(['ticket.created'], true, 'secret-a');
        $b = $this->seedSubscription(['invoice.paid', 'ticket.created'], true, 'secret-b');
        $other = $this->seedSubscription(['invoice.paid']);
        $found = [];
        foreach ($this->subscriptions()->forEvent('ticket.created') as $s) {
            $found[$s->webhookId] = $s;
        }
        $this->assertArrayHasKey($a, $found);
        $this->assertArrayHasKey($b, $found);
        $this->assertArrayNotHasKey($other, $found, 'an endpoint that does not list the event must not be returned');
        $this->assertSame('secret-a', $found[$a]->secret, 'the secret must come back decrypted');
        $this->assertSame('secret-b', $found[$b]->secret);
        $this->assertStringStartsWith('https://hooks.example.com/', $found[$a]->url);
    }

    public function testEventListsWithSeveralEventsMatchEachOfThem(): void
    {
        $id = $this->seedSubscription(['ticket.created', 'invoice.paid']);
        $this->assertContains($id, $this->ids('ticket.created'));
        $this->assertContains($id, $this->ids('invoice.paid'));
    }

    public function testAnEventNobodyListedReturnsAListWithoutOtherEndpoints(): void
    {
        $other = $this->seedSubscription(['invoice.paid']);
        $ids = $this->ids($this->uniqueEvent());
        $this->assertNotContains($other, $ids);
    }

    public function testDisabledEndpointsAreExcluded(): void
    {
        $event = $this->uniqueEvent();
        $on = $this->seedSubscription([$event]);
        $off = $this->seedSubscription([$event], false);
        $ids = $this->ids($event);
        $this->assertContains($on, $ids);
        $this->assertNotContains($off, $ids, 'a disabled endpoint must never be returned');
    }

    public function testDeletedEndpointsAreExcluded(): void
    {
        $event = $this->uniqueEvent();
        $keep = $this->seedSubscription([$event]);
        $gone = $this->seedSubscription([$event]);
        $this->deleteSubscription($gone);
        $ids = $this->ids($event);
        $this->assertContains($keep, $ids);
        $this->assertNotContains($gone, $ids, 'a deleted endpoint must never be returned');
    }

    /** @return array<string,array{string}> */
    public static function nearMissEvents(): array
    {
        return [
            'shorter' => ['ticket.creat'],
            'longer' => ['ticket.created_later'],
            'longer dotted' => ['ticket.created.extra'],
            'prefixed' => ['xticket.created'],
            'different separator' => ['ticket_created'],
            'sql like percent' => ['ticket.%'],
            'sql like underscore' => ['ticket._reated'],
            'lone percent' => ['%'],
            'lone star' => ['*'],
            'empty' => [''],
            'quote injection' => ["x' OR '1'='1"],
            'comma list' => ['ticket.created,invoice.paid'],
            'comma' => [','],
            'null byte' => ["ticket.created\0"],
            'long' => [str_repeat('a', 5000)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nearMissEvents')]
    public function testNearMissAndHostileEventNamesMatchNothingSeeded(string $event): void
    {
        $id = $this->seedSubscription(['ticket.created']);
        $this->assertNotContains($id, $this->ids($event), "'" . substr($event, 0, 40) . "' must not match an endpoint subscribed to ticket.created");
    }

    public function testStarSubscribesToEveryEvent(): void
    {
        if (!$this->supportsEventPatterns()) {
            $this->markTestSkipped('Edition declared supportsEventPatterns() === false.');
        }
        $all = $this->seedSubscription(['*']);
        $off = $this->seedSubscription(['*'], false);
        foreach (['ticket.created', 'invoice.paid', $this->uniqueEvent()] as $event) {
            $ids = $this->ids($event);
            $this->assertContains($all, $ids, "'*' must match $event");
            $this->assertNotContains($off, $ids);
        }
    }

    public function testPrefixPatternMatchesItsGroupOnly(): void
    {
        if (!$this->supportsEventPatterns()) {
            $this->markTestSkipped('Edition declared supportsEventPatterns() === false.');
        }
        $id = $this->seedSubscription(['ticket.*']);
        $this->assertContains($id, $this->ids('ticket.created'));
        $this->assertContains($id, $this->ids('ticket.' . bin2hex(random_bytes(3))), 'a pattern must also cover events the catalog does not list');
        $this->assertNotContains($id, $this->ids('invoice.paid'));
        $this->assertNotContains($id, $this->ids('tickets.created'), "'ticket.*' must not match another group that merely starts the same");
        $this->assertNotContains($id, $this->ids('ticket'), "'ticket.*' needs the dot");
    }

    public function testPatternsAndPlainEventsMixInOneSubscription(): void
    {
        if (!$this->supportsEventPatterns()) {
            $this->markTestSkipped('Edition declared supportsEventPatterns() === false.');
        }
        $id = $this->seedSubscription(['ticket.*', 'invoice.paid']);
        $this->assertContains($id, $this->ids('ticket.created'));
        $this->assertContains($id, $this->ids('invoice.paid'));
        $plain = $this->seedSubscription(['ticket.created']);
        $this->assertNotContains($plain, $this->ids('invoice.paid'), 'a plain event id is not a pattern');
    }

    public function testFindReturnsAnEnabledEndpointLikeForEvent(): void
    {
        $lookup = $this->lookup();
        if ($lookup === null) {
            $this->markTestSkipped('Adapter does not implement WebhookSubscriptionLookupInterface.');
        }
        $id = $this->seedSubscription(['ticket.created'], true, 'secret-find');
        $one = $lookup->find($id);
        $this->assertInstanceOf(WebhookSubscription::class, $one);
        $this->assertSame($id, $one->webhookId);
        $this->assertSame('secret-find', $one->secret);
        $viaEvent = null;
        foreach ($this->subscriptions()->forEvent('ticket.created') as $s) {
            if ($s->webhookId === $id) {
                $viaEvent = $s;
            }
        }
        $this->assertNotNull($viaEvent);
        $this->assertSame($viaEvent->url, $one->url, 'find() and forEvent() must agree about the endpoint');
        $this->assertSame($viaEvent->secret, $one->secret);
    }

    public function testFindReturnsNullForUnknownDisabledAndDeletedEndpoints(): void
    {
        $lookup = $this->lookup();
        if ($lookup === null) {
            $this->markTestSkipped('Adapter does not implement WebhookSubscriptionLookupInterface.');
        }
        $off = $this->seedSubscription(['ticket.created'], false);
        $gone = $this->seedSubscription(['ticket.created']);
        $this->deleteSubscription($gone);
        $this->assertNull($lookup->find($off), 'a disabled endpoint must not be found (it would be retried)');
        $this->assertNull($lookup->find($gone), 'a deleted endpoint must not be found');
        $this->assertNull($lookup->find(2_000_000_000));
        $this->assertNull($lookup->find(0));
        $this->assertNull($lookup->find(-1));
    }
}
