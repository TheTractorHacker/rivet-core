<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Hypervisor;

use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\ConfigSequence;
use RivetCore\Rmm\Support\Sql;

/**
 * The hypervisor settings: the `hypervisor_*` limits of {@see RmmSettings} and the signed configuration an agent receives. There is no separate settings table: the switch is
 * the `hypervisor` sub-switch and every threshold is a limit, so the existing settings audit, export and validation apply.
 *
 * @api
 */
final class HypervisorSettings
{
    public const LIMIT_KEYS = ['hypervisor_full_interval_s', 'hypervisor_summary_interval_s', 'hypervisor_max_vms', 'hypervisor_retention_days', 'hypervisor_history_per_vm', 'hypervisor_snapshot_days',
        'hypervisor_backup_days', 'hypervisor_storage_warn_pct', 'hypervisor_storage_crit_pct', 'hypervisor_vm_down_resolve_h', 'hypervisor_alert_vm_down', 'hypervisor_alert_backup', 'hypervisor_alert_snapshot',
        'hypervisor_alert_storage', 'hypervisor_alert_pool', 'hypervisor_alert_quorum'];

    public function __construct(private readonly RmmSettings $settings, private readonly ?ConfigSequence $sequence = null)
    {
    }

    /** @return array<string,int> */
    public function limits(): array
    {
        $all = $this->settings->limits();
        $out = [];
        foreach (self::LIMIT_KEYS as $k) {
            $out[$k] = $all[$k];
        }

        return $out;
    }

    public function enabled(): bool
    {
        return $this->settings->featureOn(HypervisorProtocol::FEATURE);
    }

    /**
     * The signed configuration for a device of the given OS, or null when there is no usable signing key (an unsigned configuration is never offered).
     *
     * @return array<string,mixed>|null
     */
    public function agentConfig(string $os): ?array
    {
        $platform = HypervisorProtocol::platformOfOs($os);
        $l = $this->limits();
        $cfg = ['v' => HypervisorProtocol::CONFIG_VERSION, 'kind' => 'hypervisor_config', 'platform' => $platform, 'full_interval_s' => $l['hypervisor_full_interval_s'],
            'summary_interval_s' => min($l['hypervisor_summary_interval_s'], $l['hypervisor_full_interval_s']), 'max_vms' => $l['hypervisor_max_vms'], 'snapshot_lookups' => 300];
        // monotonic and independent of the clock and of updated_at (rc.31, migration 0038): the agent keeps the highest seq it has seen and refuses a lower one, so an old signed
        // configuration cannot be replayed. Before rc.31 the seq was the settings row's updated_at.
        $legacy = Sql::ts((string) ($this->settings->get()['updated_at'] ?? ''));
        $cfg['seq'] = $this->sequence === null ? max(1, $legacy) : $this->sequence->seq('hypervisor:' . $platform, $cfg, $legacy);
        $cfg['version'] = substr(hash('sha256', Signer::hypervisorConfigMessage($cfg)), 0, 16);
        try {
            [$sec] = $this->settings->signingKey();
            $cfg['signature'] = Signer::sign(Signer::hypervisorConfigMessage($cfg), $sec);
        } catch (\Throwable) {
            return null;
        }
        if (strlen((string) json_encode($cfg)) > HypervisorProtocol::CONFIG_MAX_BYTES) {
            return null;
        }

        return $cfg;
    }
}
