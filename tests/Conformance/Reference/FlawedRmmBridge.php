<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Testing\InMemoryRmmBridge;

/**
 * The reference bridge with an optional named flaw: integration_not_idempotent, exists_ignores_type, link_starts_online,
 * no_move_key, remove_all, apply_creates, apply_always_true, apply_touches_time, offline_other_integration,
 * offline_unknown_too, offline_count_wrong, alert_duplicates, alert_key_global, resolve_noop, reassign_noop,
 * reassign_everything, script_any, session_swap.
 */
final class FlawedRmmBridge extends InMemoryRmmBridge
{
    public function __construct(private ?string $flaw = null)
    {
        parent::__construct();
    }

    public function ensureIntegration(string $type, string $name): int
    {
        return parent::ensureIntegration($this->flaw === 'integration_not_idempotent' ? $type . random_int(1, PHP_INT_MAX) : $type, $name);
    }

    public function integrationExists(int $integrationId, string $type): bool
    {
        if ($this->flaw === 'exists_ignores_type') {
            return isset($this->integrations[$integrationId]);
        }

        return parent::integrationExists($integrationId, $type);
    }

    public function upsertLink(int $integrationId, int $assetId, string $agentKey, array $facts): void
    {
        if ($this->flaw === 'no_move_key' && $this->link($integrationId, $agentKey) !== null) {
            return;
        }
        parent::upsertLink($integrationId, $assetId, $agentKey, $facts);
        if ($this->flaw === 'link_starts_online') {
            $this->applyHealth($integrationId, $assetId, ['hostname' => $facts['hostname']] + []);
        }
    }

    public function removeLink(int $integrationId, string $agentKey): void
    {
        if ($this->flaw === 'remove_all') {
            $this->links = array_values(array_filter($this->links, static fn (array $l): bool => $l['integration_id'] !== $integrationId));

            return;
        }
        parent::removeLink($integrationId, $agentKey);
    }

    public function applyHealth(int $integrationId, int $assetId, array $health): bool
    {
        if ($this->flaw === 'apply_always_true') {
            parent::applyHealth($integrationId, $assetId, $health);

            return true;
        }
        if ($this->flaw === 'apply_creates' && $this->linkOfAsset($integrationId, $assetId) === null) {
            parent::upsertLink($integrationId, $assetId, 'created:' . $assetId, ['hostname' => $health['hostname'], 'os_name' => '', 'os_version' => '', 'manufacturer' => '', 'model' => '']);
        }
        if ($this->flaw === 'apply_touches_time') {
            foreach ($this->links as &$l) {
                if ($l['integration_id'] === $integrationId && $l['asset_id'] === $assetId) {
                    $l['status'] = 'unknown';
                }
            }
            unset($l);
        }

        return parent::applyHealth($integrationId, $assetId, $health);
    }

    private function linkOfAsset(int $integrationId, int $assetId): ?int
    {
        foreach ($this->links as $i => $l) {
            if ($l['integration_id'] === $integrationId && $l['asset_id'] === $assetId) {
                return $i;
            }
        }

        return null;
    }

    public function markOffline(int $integrationId, array $agentKeys): int
    {
        if ($this->flaw === 'offline_other_integration' || $this->flaw === 'offline_unknown_too') {
            $n = 0;
            foreach ($this->links as &$l) {
                $status = $this->flaw === 'offline_unknown_too' ? in_array($l['status'], ['online', 'unknown'], true) : $l['status'] === 'online';
                $scope = $this->flaw === 'offline_other_integration' || $l['integration_id'] === $integrationId;
                if ($scope && $status && in_array($l['agent_key'], $agentKeys, true)) {
                    $l['status'] = 'offline';
                    $l['status_changed_at'] = $this->now();
                    ++$n;
                }
            }

            return $n;
        }
        $n = parent::markOffline($integrationId, $agentKeys);

        return $this->flaw === 'offline_count_wrong' ? count($agentKeys) : $n;
    }

    public function openAlert(int $integrationId, string $alertKey, ?int $assetId, int $clientId, string $severity, string $message, array $raw): int
    {
        if ($this->flaw === 'alert_duplicates') {
            return parent::openAlert($integrationId, $alertKey . '#' . $this->next, $assetId, $clientId, $severity, $message, $raw);
        }
        if ($this->flaw === 'alert_key_global') {
            foreach ($this->alerts as $id => $a) {
                if ($a['key'] === $alertKey) {
                    return $id;
                }
            }
        }

        return parent::openAlert($integrationId, $alertKey, $assetId, $clientId, $severity, $message, $raw);
    }

    public function resolveAlert(int $integrationId, int $alertId): void
    {
        if ($this->flaw !== 'resolve_noop') {
            parent::resolveAlert($integrationId, $alertId);
        }
    }

    public function reassignAlerts(int $integrationId, int $assetId, int $clientId): void
    {
        if ($this->flaw === 'reassign_noop') {
            return;
        }
        if ($this->flaw === 'reassign_everything') {
            foreach ($this->alerts as &$a) {
                $a['client_id'] = $clientId;
            }

            return;
        }
        parent::reassignAlerts($integrationId, $assetId, $clientId);
    }

    public function savedPowerShellScript(int $scriptId): ?string
    {
        if ($this->flaw === 'script_any') {
            return $this->scripts[$scriptId]['body'] ?? null;
        }

        return parent::savedPowerShellScript($scriptId);
    }

    public function recordRemoteSession(int $assetId, int $clientId, int $userId, string $connectionType, string $reference, ?string $ipAddress, ?string $userAgent): void
    {
        if ($this->flaw === 'session_swap') {
            parent::recordRemoteSession($assetId, $userId, $clientId, $connectionType, $reference, $ipAddress, $userAgent);

            return;
        }
        parent::recordRemoteSession($assetId, $clientId, $userId, $connectionType, $reference, $ipAddress, $userAgent);
    }
}
