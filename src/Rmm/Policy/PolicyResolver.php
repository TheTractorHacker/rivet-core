<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Policy;

/**
 * Resolves the policies that reach one device into one decision per setting key. Pure: no database, no clock, the same layers in any order
 * give the same answer (the tests shuffle them).
 *
 * PRECEDENCE, least to most specific: global, client, site, group, tag, device. The most specific layer that says something about a key wins
 * that key; a key nobody mentions is not in the result. What a layer says is `override` (a value), `disable` (the key is off) or `inherit`
 * (nothing: the key is dropped from that layer, see {@see PolicyLayer::merge()}).
 *
 * CONFLICTS inside one scope level (a device in two groups, carrying two tags, two policies at the client): the layer with the higher
 * `priority` wins, then the newer assignment (higher assignment id). That order is total, so there is never a tie.
 *
 * ENFORCE: a layer flagged `enforce` cannot be overridden by anything more specific. When several enforced layers set the same key, the LEAST
 * specific one wins (the outermost authority has the last word), ties broken by priority and assignment id as above.
 *
 * @api
 */
final class PolicyResolver
{
    /** scope type => rank; a higher rank is more specific. */
    public const SCOPES = ['global' => 0, 'client' => 1, 'site' => 2, 'group' => 3, 'tag' => 4, 'device' => 5];

    /**
     * @param list<PolicyLayer> $layers
     * @return array<string,array{mode:string,value:mixed,source:array{assignment_id:int,policy_id:int,scope_type:string,enforced:bool}}> key => decision, keys sorted
     */
    public static function resolve(array $layers): array
    {
        $byKey = [];
        foreach ($layers as $layer) {
            if (!isset(self::SCOPES[$layer->scopeType])) {
                throw new \InvalidArgumentException('Unknown scope type ' . $layer->scopeType);
            }
            foreach ($layer->settings as $key => $entry) {
                if ($entry['mode'] === 'override' || $entry['mode'] === 'disable') {
                    $byKey[$key][] = [$layer, $entry];
                }
            }
        }
        ksort($byKey, SORT_STRING);
        $out = [];
        foreach ($byKey as $key => $candidates) {
            $enforced = array_values(array_filter($candidates, static fn (array $c): bool => $c[0]->enforce));
            if ($enforced !== []) {
                usort($enforced, static fn (array $a, array $b): int => self::compare($a[0], $b[0], true));
                [$layer, $entry] = $enforced[0];
            } else {
                usort($candidates, static fn (array $a, array $b): int => self::compare($a[0], $b[0], false));
                [$layer, $entry] = $candidates[0];
            }
            $out[$key] = ['mode' => $entry['mode'], 'value' => $entry['value'],
                'source' => ['assignment_id' => $layer->assignmentId, 'policy_id' => $layer->policyId, 'scope_type' => $layer->scopeType, 'enforced' => $layer->enforce]];
        }

        return $out;
    }

    /** Negative when $a beats $b. */
    private static function compare(PolicyLayer $a, PolicyLayer $b, bool $leastSpecificFirst): int
    {
        $ra = self::SCOPES[$a->scopeType];
        $rb = self::SCOPES[$b->scopeType];
        if ($ra !== $rb) {
            return $leastSpecificFirst ? $ra <=> $rb : $rb <=> $ra;
        }
        if ($a->priority !== $b->priority) {
            return $b->priority <=> $a->priority;
        }

        return $b->assignmentId <=> $a->assignmentId;
    }
}
