<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\ExternalMetricStoreInterface;
use RivetCore\Rmm\Metrics\VictoriaMetricsStore;
use RivetCore\Testing\ExternalMetricStoreConformanceTestCase;
use RivetCore\Tests\Support\FakeVictoriaMetricsServer;
use RivetCore\Webhooks\UrlPolicy;

/** The external store kit against the VictoriaMetrics adapter, over HTTP, talking to the fake server on loopback. */
final class VictoriaMetricsStoreKitTest extends ExternalMetricStoreConformanceTestCase
{
    private static ?FakeVictoriaMetricsServer $server = null;
    private ?VictoriaMetricsStore $store = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new FakeVictoriaMetricsServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        self::$server?->reset();
    }

    protected function store(): ExternalMetricStoreInterface
    {
        // allowPrivate only because the fake server listens on loopback; real deployments use the default policy
        return $this->store ??= new VictoriaMetricsStore((self::$server?->url() ?? ''), new UrlPolicy(true), null, null, null, null, 5, true);
    }

    protected function breakStore(): ?callable
    {
        return function (): callable {
            self::$server?->setMode(['status' => 500]);

            return static function (): void {
                self::$server?->setMode([]);
            };
        };
    }
}
