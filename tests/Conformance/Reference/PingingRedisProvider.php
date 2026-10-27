<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Redis\RedisClientProviderInterface;

/**
 * Behaves like an edition provider (probes the server, null when it is down) or has a named flaw:
 * throws_when_down, lazy_client_when_down, null_when_up, other_database.
 */
final class PingingRedisProvider implements RedisClientProviderInterface
{
    private int $calls = 0;

    public function __construct(private int $port, private ?string $flaw = null)
    {
    }

    public function client(): ?\Predis\Client
    {
        $this->calls++;
        $options = ['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => $this->port, 'timeout' => 0.5];
        if ($this->flaw === 'other_database' && $this->calls > 1) {
            $options['database'] = 3;
        }
        $client = new \Predis\Client($options);
        if ($this->flaw === 'lazy_client_when_down') {
            return $client;
        }
        if ($this->flaw === 'null_when_up') {
            return null;
        }
        try {
            $client->ping();
        } catch (\Throwable $e) {
            if ($this->flaw === 'throws_when_down') {
                throw $e;
            }

            return null;
        }

        return $client;
    }
}
