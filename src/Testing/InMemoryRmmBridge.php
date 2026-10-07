<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Support\SystemClock;

/**
 * Reference implementation (not API; tests may extend it to build a deliberately broken variant) of {@see RmmBridgeInterface} over arrays (integrations, links, alerts, saved scripts, session log).
 *
 * @internal
 */
class InMemoryRmmBridge implements RmmBridgeInterface
{
    /** @var array<int,array{type:string,name:string}> */
    protected array $integrations = [];
    /** @var list<array{integration_id:int,asset_id:int,agent_key:string,status:string,status_changed_at:?string,hostname:string}> */
    protected array $links = [];
    /** @var array<int,array{integration_id:int,key:string,status:string,client_id:int,asset_id:?int,severity:string,message:string,raw:array<string,mixed>}> */
    protected array $alerts = [];
    /** @var array<int,array{body:string,powershell:bool,enabled:bool}> */
    protected array $scripts = [];
    /** @var list<array{asset_id:int,client_id:int,user_id:int,connection_type:string,reference:string,ip_address:?string,user_agent:?string}> */
    protected array $sessions = [];
    /** @var list<int> alert ids whose ticket auto-close ran */
    protected array $autoClosed = [];
    protected int $next = 1;
    protected ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    // ------------------------------------------------------------- test inspection

    /** @return array{asset_id:int,status:string,status_changed_at:?string,hostname:string}|null */
    public function link(int $integrationId, string $agentKey): ?array
    {
        foreach ($this->links as $l) {
            if ($l['integration_id'] === $integrationId && $l['agent_key'] === $agentKey) {
                return ['asset_id' => $l['asset_id'], 'status' => $l['status'], 'status_changed_at' => $l['status_changed_at'], 'hostname' => $l['hostname']];
            }
        }

        return null;
    }

    public function linkCount(int $integrationId): int
    {
        return count(array_filter($this->links, static fn (array $l): bool => $l['integration_id'] === $integrationId));
    }

    public function backdateStatusChange(int $integrationId, string $agentKey): void
    {
        foreach ($this->links as &$l) {
            if ($l['integration_id'] === $integrationId && $l['agent_key'] === $agentKey) {
                $l['status_changed_at'] = '2000-01-01 00:00:00';
            }
        }
    }

    /** @return array{status:string,client_id:int,asset_id:?int,severity:string,message:string}|null */
    public function alert(int $alertId): ?array
    {
        $a = $this->alerts[$alertId] ?? null;

        return $a === null ? null : ['status' => $a['status'], 'client_id' => $a['client_id'], 'asset_id' => $a['asset_id'], 'severity' => $a['severity'], 'message' => $a['message']];
    }

    public function alertCount(int $integrationId, string $alertKey): int
    {
        return count(array_filter($this->alerts, static fn (array $a): bool => $a['integration_id'] === $integrationId && $a['key'] === $alertKey));
    }

    /** @return list<int> */
    public function autoClosedAlerts(): array
    {
        return $this->autoClosed;
    }

    public function addScript(string $body, bool $powershell = true, bool $enabled = true): int
    {
        $this->scripts[$this->next] = ['body' => $body, 'powershell' => $powershell, 'enabled' => $enabled];

        return $this->next++;
    }

    /** @return list<array{asset_id:int,client_id:int,user_id:int,connection_type:string,reference:string,ip_address:?string,user_agent:?string}> */
    public function sessions(): array
    {
        return $this->sessions;
    }

    // ------------------------------------------------------------- contract

    public function ensureIntegration(string $type, string $name): int
    {
        foreach ($this->integrations as $id => $i) {
            if ($i['type'] === $type) {
                return $id;
            }
        }
        $this->integrations[$this->next] = ['type' => $type, 'name' => $name];

        return $this->next++;
    }

    public function integrationExists(int $integrationId, string $type): bool
    {
        return ($this->integrations[$integrationId]['type'] ?? null) === $type;
    }

    public function upsertLink(int $integrationId, int $assetId, string $agentKey, array $facts): void
    {
        $this->links = array_values(array_filter(
            $this->links,
            static fn (array $l): bool => !($l['integration_id'] === $integrationId && $l['agent_key'] === $agentKey && $l['asset_id'] !== $assetId)
        ));
        foreach ($this->links as &$l) {
            if ($l['integration_id'] === $integrationId && $l['asset_id'] === $assetId) {
                $l['agent_key'] = $agentKey;
                $l['hostname'] = $facts['hostname'];

                return;
            }
        }
        unset($l);
        $this->links[] = ['integration_id' => $integrationId, 'asset_id' => $assetId, 'agent_key' => $agentKey, 'status' => 'unknown', 'status_changed_at' => null, 'hostname' => $facts['hostname']];
    }

    public function removeLink(int $integrationId, string $agentKey): void
    {
        $this->links = array_values(array_filter(
            $this->links,
            static fn (array $l): bool => !($l['integration_id'] === $integrationId && $l['agent_key'] === $agentKey)
        ));
    }

    public function applyHealth(int $integrationId, int $assetId, array $health): bool
    {
        foreach ($this->links as &$l) {
            if ($l['integration_id'] === $integrationId && $l['asset_id'] === $assetId) {
                if ($l['status'] !== 'online') {
                    $l['status_changed_at'] = $this->now();
                }
                $l['status'] = 'online';
                $l['hostname'] = $health['hostname'];

                return true;
            }
        }

        return false;
    }

    public function markOffline(int $integrationId, array $agentKeys): int
    {
        $n = 0;
        foreach ($this->links as &$l) {
            if ($l['integration_id'] === $integrationId && $l['status'] === 'online' && in_array($l['agent_key'], $agentKeys, true)) {
                $l['status'] = 'offline';
                $l['status_changed_at'] = $this->now();
                ++$n;
            }
        }

        return $n;
    }

    public function openAlert(int $integrationId, string $alertKey, ?int $assetId, int $clientId, string $severity, string $message, array $raw): int
    {
        foreach ($this->alerts as $id => $a) {
            if ($a['integration_id'] === $integrationId && $a['key'] === $alertKey) {
                return $id;
            }
        }
        $this->alerts[$this->next] = ['integration_id' => $integrationId, 'key' => $alertKey, 'status' => 'new', 'client_id' => $clientId, 'asset_id' => $assetId, 'severity' => $severity, 'message' => $message, 'raw' => $raw];

        return $this->next++;
    }

    public function resolveAlert(int $integrationId, int $alertId): void
    {
        $a = $this->alerts[$alertId] ?? null;
        if ($a === null || $a['integration_id'] !== $integrationId || $a['status'] === 'resolved') {
            return;
        }
        $this->alerts[$alertId]['status'] = 'resolved';
        $this->autoClosed[] = $alertId;
    }

    public function reassignAlerts(int $integrationId, int $assetId, int $clientId): void
    {
        foreach ($this->alerts as &$a) {
            if ($a['integration_id'] === $integrationId && $a['asset_id'] === $assetId && $a['status'] !== 'resolved') {
                $a['client_id'] = $clientId;
            }
        }
    }

    public function savedPowerShellScript(int $scriptId): ?string
    {
        $s = $this->scripts[$scriptId] ?? null;

        return $s !== null && $s['enabled'] && $s['powershell'] ? $s['body'] : null;
    }

    public function recordRemoteSession(int $assetId, int $clientId, int $userId, string $connectionType, string $reference, ?string $ipAddress, ?string $userAgent): void
    {
        $this->sessions[] = ['asset_id' => $assetId, 'client_id' => $clientId, 'user_id' => $userId, 'connection_type' => $connectionType, 'reference' => $reference, 'ip_address' => $ipAddress, 'user_agent' => $userAgent];
    }

    protected function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
