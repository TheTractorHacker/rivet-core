<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Checks;

use RivetCore\Rmm\Support\Sql;

/**
 * Stale check cleanup (1.0.0-rc.24). A check key that is no longer delivered to a device (not for its OS, taken off the schedule, removed by a
 * policy) leaves its `endpoint_agent_checks` row behind, and with it an open alert nobody can ever recover. This removes the row and resolves
 * the alert ("This check no longer applies to the device", resolved by the system, no ticket, no re-alert).
 *
 * Lazy and cheap: {@see reconcile()} runs at check-in with the keys the response is about to deliver, costs one primary-key read of
 * `rmm_device_checkset`, and does the real work (and one write) only when the delivered set differs from the one stored. Keys the server itself
 * reports (log, change and patch rules, {@see RmmChecks::SERVER_KEY_PREFIXES}) are never touched.
 *
 * @api
 */
final class CheckReconciler
{
    public function __construct(private readonly Sql $sql, private readonly CheckEvaluator $checks)
    {
    }

    /**
     * @param array<string,mixed> $dev the device row
     * @param list<string> $delivered the check keys delivered to the device now
     * @return int checks removed (0 when the delivered set did not change)
     */
    public function reconcile(array $dev, array $delivered, bool $force = false): int
    {
        $deviceId = (int) $dev['device_id'];
        $keys = array_values(array_unique($delivered));
        sort($keys, SORT_STRING);
        $hash = substr(hash('sha256', implode("\n", $keys)), 0, 16);
        $row = $this->sql->one('SELECT set_hash FROM rmm_device_checkset WHERE device_id = ?', [$deviceId]);
        if (!$force && $row !== null && $row['set_hash'] === $hash) {
            return 0;
        }
        $removed = $this->checks->removeStale($dev, $keys);
        $this->sql->run('INSERT INTO rmm_device_checkset (device_id, set_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE set_hash = VALUES(set_hash), updated_at = CURRENT_TIMESTAMP', [$deviceId, $hash]);

        return $removed;
    }
}
