<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Testing\RmmBridgeConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedRmmBridge;

/** The kit against the in-memory bridge (and the harness target for the bridge mutants). */
final class RmmBridgeKitTest extends RmmBridgeConformanceTestCase
{
    use Flaw;

    private ?FlawedRmmBridge $store = null;
    private int $assets = 1000;

    private function store(): FlawedRmmBridge
    {
        return $this->store ??= new FlawedRmmBridge(self::$flaw);
    }

    protected function bridge(): RmmBridgeInterface
    {
        return $this->store();
    }

    protected function createAsset(): int
    {
        return ++$this->assets;
    }

    protected function readLink(int $integrationId, string $agentKey): ?array
    {
        $l = $this->store()->link($integrationId, $agentKey);

        return $l === null ? null : ['asset_id' => $l['asset_id'], 'status' => $l['status'], 'status_changed_at' => $l['status_changed_at']];
    }

    protected function backdateStatusChange(int $integrationId, string $agentKey): void
    {
        $this->store()->backdateStatusChange($integrationId, $agentKey);
    }

    protected function readAlert(int $alertId): ?array
    {
        $a = $this->store()->alert($alertId);

        return $a === null ? null : ['status' => $a['status'], 'client_id' => $a['client_id'], 'asset_id' => $a['asset_id'], 'severity' => $a['severity']];
    }

    protected function countAlerts(int $integrationId, string $alertKey): int
    {
        return $this->store()->alertCount($integrationId, $alertKey);
    }

    protected function createScript(string $body, bool $powershell, bool $enabled): int
    {
        return $this->store()->addScript($body, $powershell, $enabled);
    }

    protected function readRemoteSessions(int $assetId): array
    {
        $out = [];
        foreach ($this->store()->sessions() as $s) {
            if ($s['asset_id'] === $assetId) {
                $out[] = ['client_id' => $s['client_id'], 'user_id' => $s['user_id'], 'connection_type' => $s['connection_type'], 'reference' => $s['reference'], 'ip_address' => $s['ip_address'], 'user_agent' => $s['user_agent']];
            }
        }

        return $out;
    }
}
