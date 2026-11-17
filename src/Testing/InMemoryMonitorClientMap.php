<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Monitoring\Inbound\InboundEvent;
use RivetCore\Monitoring\Inbound\MonitorClientMapInterface;
use RivetCore\Monitoring\Inbound\MonitorTarget;

/**
 * Reference implementation (not API) of {@see MonitorClientMapInterface}, with the ambiguity rule an edition must keep: an id entry wins over tag
 * entries when they agree or there are no tags; two different targets among the matches (two tags, or an id entry and a tag) resolve to null.
 *
 * @internal
 */
class InMemoryMonitorClientMap implements MonitorClientMapInterface
{
    /** @var array<string,MonitorTarget> */
    private array $byId = [];
    /** @var array<string,MonitorTarget> */
    private array $byTag = [];

    public function mapMonitor(string $monitorKey, MonitorTarget $t): self
    {
        $this->byId[$monitorKey] = $t;

        return $this;
    }

    /** @param string $tag "name" or "name:value", matched exactly (case-insensitive) */
    public function mapTag(string $tag, MonitorTarget $t): self
    {
        $this->byTag[strtolower($tag)] = $t;

        return $this;
    }

    public function resolve(InboundEvent $event): ?MonitorTarget
    {
        $hits = [];
        if (isset($this->byId[$event->monitorKey])) {
            $hits[] = $this->byId[$event->monitorKey];
        }
        foreach ($event->tags as $tag) {
            if (isset($this->byTag[strtolower($tag)])) {
                $hits[] = $this->byTag[strtolower($tag)];
            }
        }
        if ($hits === []) {
            return null;
        }
        foreach ($hits as $h) {
            if ($h->clientId !== $hits[0]->clientId) {
                return null;
            }
        }

        return $hits[0];
    }
}
