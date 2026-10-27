<?php

declare(strict_types=1);

namespace RivetCore\Security;

use RivetCore\Audit\AuditService;

/**
 * The one way to change a baseline setting: validates against {@see SecurityBaseline} and writes an audit row for every attempt, accepted
 * (`baseline.setting_changed`) or refused (`baseline.change_refused`). The caller stores the returned value; this class does not persist.
 *
 * @api
 */
final class BaselineChanges
{
    public function __construct(private AuditService $audit)
    {
    }

    /**
     * @param int|bool|null $old the value before the change (null when the setting was never stored)
     * @return int|bool the validated value to store
     * @throws BaselineViolation when the request is refused; the refusal is audit-logged first
     */
    public function change(?int $actorUserId, string $key, int|bool|null $old, mixed $requested, ?string $scope = null): int|bool
    {
        $oldEffective = SecurityBaseline::effective($key, $old);
        try {
            $new = SecurityBaseline::validate($key, $requested);
        } catch (BaselineViolation $e) {
            $this->audit->log('baseline.change_refused', $actorUserId, 'baseline_setting', $key, 'refused', $e->getMessage(), [
                'setting' => $key, 'scope' => $scope, 'old' => $oldEffective, 'requested' => is_scalar($requested) ? $requested : gettype($requested),
            ]);
            throw $e;
        }
        $this->audit->log('baseline.setting_changed', $actorUserId, 'baseline_setting', $key, 'changed', "Baseline setting {$key} changed.", [
            'setting' => $key, 'scope' => $scope, 'old' => $oldEffective, 'new' => $new,
        ]);

        return $new;
    }
}
