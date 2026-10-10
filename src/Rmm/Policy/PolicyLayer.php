<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Policy;

/**
 * One policy as it applies to a device through one assignment: the settings of the policy with the assignment's own overrides already
 * laid over them (an `inherit` override removes the key from this layer). Plain data, built by {@see PolicyStore} or by a test.
 *
 * @api
 */
final class PolicyLayer
{
    /**
     * @param string $scopeType one of {@see PolicyResolver::SCOPES}
     * @param array<string,array{mode:string,value:mixed}> $settings normalised, see {@see PolicySettings::normalize()}
     */
    public function __construct(
        public readonly int $assignmentId,
        public readonly int $policyId,
        public readonly string $scopeType,
        public readonly int $priority,
        public readonly bool $enforce,
        public readonly array $settings,
    ) {
    }

    /**
     * Lay an assignment's overrides over a policy's settings.
     *
     * @param array<string,array{mode:string,value:mixed}> $policy
     * @param array<string,array{mode:string,value:mixed}> $overrides
     * @return array<string,array{mode:string,value:mixed}>
     */
    public static function merge(array $policy, array $overrides): array
    {
        foreach ($overrides as $key => $entry) {
            if ($entry['mode'] === 'inherit') {
                unset($policy[$key]);
            } else {
                $policy[$key] = $entry;
            }
        }
        ksort($policy, SORT_STRING);

        return $policy;
    }
}
