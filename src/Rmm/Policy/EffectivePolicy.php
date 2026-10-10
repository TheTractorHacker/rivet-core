<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Policy;

use RivetCore\Rmm\Settings\RmmSettings;

/**
 * What one device is told, given the policies that reach it: loads the device's layers, resolves them ({@see PolicyResolver}) and lays the
 * decisions over the instance's own configuration ({@see EffectiveConfig}). One read of the assignment table per call.
 *
 * @api
 */
final class EffectivePolicy
{
    public function __construct(private readonly RmmSettings $settings, private readonly PolicyStore $store)
    {
    }

    /**
     * @param array<string,mixed> $dev the device row
     * @param list<string>|null $capabilities what the device announced
     * @return array{applied:bool,checks:list<array<string,mixed>>,check_in_interval_s:int,collect_interval_s:int,features:array<string,bool>,ring:?string,version:string,sources:array<string,array<string,mixed>>}
     */
    public function forDevice(array $dev, ?array $capabilities = null): array
    {
        $cfg = $this->settings->get();

        return EffectiveConfig::compose(PolicyResolver::resolve($this->store->layersFor($dev)), $this->settings->checks(), (int) $cfg['check_in_interval_s'], (int) $cfg['collect_interval_s'], $capabilities);
    }

    /**
     * The same decision with the layers that produced it, for a technician asking "why does this device behave like this".
     *
     * @param array<string,mixed> $dev
     * @param list<string>|null $capabilities
     * @return array<string,mixed>
     */
    public function explain(array $dev, ?array $capabilities = null): array
    {
        $layers = $this->store->layersFor($dev);
        $eff = $this->forDevice($dev, $capabilities);
        $names = [];
        foreach ($layers as $l) {
            $names[$l->policyId] ??= (string) ($this->store->find($l->policyId)['name'] ?? '');
        }

        return [
            'applied' => $eff['applied'],
            'version' => $eff['version'],
            'check_in_interval_s' => $eff['check_in_interval_s'],
            'collect_interval_s' => $eff['collect_interval_s'],
            'features' => $eff['features'],
            'ring' => $eff['ring'],
            'checks' => array_map(static fn (array $c): array => ['key' => $c['key'], 'type' => $c['type'], 'params' => $c['params'], 'interval_s' => $c['interval_s'], 'from' => $eff['sources']['check.' . $c['key']] ?? null], $eff['checks']),
            'sources' => $eff['sources'] === [] ? new \stdClass() : $eff['sources'],
            'layers' => array_map(static fn (PolicyLayer $l): array => ['assignment_id' => $l->assignmentId, 'policy_id' => $l->policyId, 'policy' => $names[$l->policyId] ?? '',
                'scope_type' => $l->scopeType, 'priority' => $l->priority, 'enforce' => $l->enforce], $layers),
        ];
    }
}
