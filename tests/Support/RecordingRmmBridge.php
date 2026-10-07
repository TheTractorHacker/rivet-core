<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Testing\InMemoryRmmBridge;

/** The in-memory bridge that also remembers the health pushed into each link and can be told to fail. */
final class RecordingRmmBridge extends InMemoryRmmBridge
{
    /** @var array<string,array<string,mixed>> "<integration>:<asset>" => the last health */
    public array $health = [];
    /** @var list<int> chunk sizes markOffline was called with */
    public array $offlineChunks = [];
    public ?\Throwable $failOn = null;
    public ?string $failMethod = null;

    public function applyHealth(int $integrationId, int $assetId, array $health): bool
    {
        $this->maybeFail('applyHealth');
        $ok = parent::applyHealth($integrationId, $assetId, $health);
        if ($ok) {
            $this->health[$integrationId . ':' . $assetId] = $health;
        }

        return $ok;
    }

    public function upsertLink(int $integrationId, int $assetId, string $agentKey, array $facts): void
    {
        $this->maybeFail('upsertLink');
        parent::upsertLink($integrationId, $assetId, $agentKey, $facts);
    }

    public function markOffline(int $integrationId, array $agentKeys): int
    {
        $this->offlineChunks[] = count($agentKeys);

        return parent::markOffline($integrationId, $agentKeys);
    }

    public function openAlert(int $integrationId, string $alertKey, ?int $assetId, int $clientId, string $severity, string $message, array $raw): int
    {
        $this->maybeFail('openAlert');

        return parent::openAlert($integrationId, $alertKey, $assetId, $clientId, $severity, $message, $raw);
    }

    /** @return array<string,mixed>|null */
    public function healthOf(int $integrationId, int $assetId): ?array
    {
        return $this->health[$integrationId . ':' . $assetId] ?? null;
    }

    public function alertKeys(): array
    {
        return array_values(array_map(static fn (array $a): string => $a['key'], $this->alerts));
    }

    /** @return array{raw:array<string,mixed>}|null */
    public function alertRaw(int $id): ?array
    {
        return isset($this->alerts[$id]) ? ['raw' => $this->alerts[$id]['raw']] : null;
    }

    private function maybeFail(string $method): void
    {
        if ($this->failOn !== null && $this->failMethod === $method) {
            throw $this->failOn;
        }
    }
}
