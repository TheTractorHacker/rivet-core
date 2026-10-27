<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use RivetCore\Tests\Support\FakeDatabase;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Webhooks\UrlPolicy;
use RivetCore\Webhooks\WebhookDispatcher;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/** GAP-08: plain http webhook destinations need an explicit per-destination flag when the dispatcher is told to require https. */
final class HttpsOnlyDeliveryTest extends TestCase
{
    private int $sent = 0;

    private function dispatcher(string $url, array $options, bool $requireHttps, ?UrlPolicy $policy = null): WebhookDispatcher
    {
        $subs = new class($url, $options) implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface {
            public function __construct(private string $url, private array $options)
            {
            }

            public function forEvent(string $eventType): array
            {
                return [$this->find(7)];
            }

            public function find(int $webhookId): WebhookSubscription
            {
                return new WebhookSubscription(7, $this->url, 's', $this->options);
            }
        };

        return new WebhookDispatcher(new FakeDatabase(), $subs, new FixedClock(), ['X-T'], function () {
            ++$this->sent;

            return ['status' => 200, 'body' => '', 'error' => null];
        }, 10, $policy, false, $requireHttps);
    }

    public function testDefaultStaysCompatibleAndSendsHttp(): void
    {
        $r = $this->dispatcher('http://h.example/a', [], false)->deliver('e', [])[0];
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $this->sent);
    }

    public function testRequireHttpsRefusesHttpWithoutTheFlag(): void
    {
        foreach (['http://h.example/a', 'HTTP://h.example/a'] as $url) {
            $r = $this->dispatcher($url, [], true)->deliver('e', [])[0];
            $this->assertFalse($r['ok']);
            $this->assertSame('plain http endpoint not allowed', $r['error']);
        }
        $this->assertSame(0, $this->sent, 'the endpoint is never contacted');
    }

    public function testRequireHttpsStillSendsHttpsAndFlaggedHttp(): void
    {
        $this->assertTrue($this->dispatcher('https://h.example/a', [], true)->deliver('e', [])[0]['ok']);
        $this->assertTrue($this->dispatcher('http://h.example/a', ['allow_http' => true], true)->deliver('e', [])[0]['ok']);
        $this->assertSame(2, $this->sent);
    }

    public function testOnlyAStrictTrueFlagCountsAndPerJobOptionsCannotSetIt(): void
    {
        foreach (['1', 1, 'true', 'yes'] as $v) {
            $this->assertFalse($this->dispatcher('http://h.example/a', ['allow_http' => $v], true)->deliver('e', [])[0]['ok']);
        }
        $r = $this->dispatcher('http://h.example/a', [], true)->deliverTo(7, 'e', [], 1, null, null, ['allow_http' => true]);
        $this->assertFalse($r['ok'], 'job options must not lift the restriction');
        $this->assertSame(0, $this->sent);
    }

    public function testFormattedDeliveryIsCoveredToo(): void
    {
        $r = $this->dispatcher('http://h.example/a', ['format' => 'json'], true)->deliver('e', [])[0];
        $this->assertSame('plain http endpoint not allowed', $r['error']);
    }

    public function testUrlPolicyHttpArgument(): void
    {
        $p = new UrlPolicy(false, static fn (): array => ['93.184.216.34']);
        $this->assertNotNull($p->vet('http://x.example/'), 'the default is unchanged');
        $this->assertNull($p->vet('http://x.example/', false));
        $this->assertNotNull($p->vet('https://x.example/', false));
        $this->assertFalse($p->isSafe('http://x.example/', false));
        $this->assertTrue($p->isSafe('http://x.example/'));
    }

    public function testDestinationProblemForTheSaveForm(): void
    {
        $p = new UrlPolicy(false, static fn (): array => ['93.184.216.34']);
        $this->assertStringContainsString('Plain http', (string) $p->destinationProblem('http://x.example/'));
        $this->assertNull($p->destinationProblem('http://x.example/', true));
        $this->assertNull($p->destinationProblem('https://x.example/'));
        $this->assertNotNull($p->destinationProblem('https://127.0.0.1/'));
        $this->assertNotNull($p->destinationProblem('ftp://x.example/', true));
        $this->assertTrue(UrlPolicy::isPlainHttp('hTTp://x'));
        $this->assertFalse(UrlPolicy::isPlainHttp('not a url'));
    }
}
