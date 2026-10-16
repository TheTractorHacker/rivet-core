<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

use RivetCore\Rmm\Checks\CheckEvaluator;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Turns a device's patch state into the two alert checks of the Phase 3 engine (they appear next to the agent's own checks and follow the same
 * debounce, ownership, escalation and maintenance rules):
 *
 *  - `patch.compliance`: ok; warn when a critical or security update has been missing for `patch_overdue_days`; fail at twice that; warn when the last good scan is older than
 *    three scan intervals. Declined updates are a decision and do not count.
 *  - `patch.install_failed`: fail while an update the module tried to install is still marked failed (it recovers when the update installs, the device stops listing it
 *    or an administrator declines it). Only devices that ever failed get this check.
 *
 * Nothing here runs while the `patching` switch is off.
 *
 * @api
 */
final class PatchComplianceService
{
    public function __construct(private readonly Sql $sql, private readonly RmmSettings $settings, private readonly CheckEvaluator $checks)
    {
    }

    /**
     * @param array<string,mixed> $dev
     * @return array{compliance:string,install_failed:?string}
     */
    public function evaluate(array $dev): array
    {
        $out = ['compliance' => 'skipped', 'install_failed' => null];
        if (!$this->settings->featureOn(PatchProtocol::FEATURE)) {
            return $out;
        }
        $deviceId = (int) $dev['device_id'];
        $limits = $this->settings->limits();
        $scan = $this->sql->one('SELECT scanned_at, scan_state FROM rmm_patch_scans WHERE device_id = ?', [$deviceId]);
        if ($scan === null || $scan['scanned_at'] === null) {
            return $out;   // never scanned: nothing to say yet
        }
        $cfg = $this->settings->get();
        $failN = max(1, (int) $cfg['failure_debounce']);
        $okN = max(1, (int) $cfg['recovery_debounce']);
        $at = $this->sql->utcNow();
        $results = [];

        $days = (int) $limits['patch_overdue_days'];
        $overdue = $this->sql->one("SELECT COUNT(*) AS c, MIN(first_seen_at) AS oldest FROM rmm_patch_available
            WHERE device_id = ? AND kind IN ('critical','security') AND status IN ('available','approved','failed') AND first_seen_at <= ?", [$deviceId, $this->sql->utcAt(-$days * 86400)]);
        $n = (int) ($overdue['c'] ?? 0);
        $status = 'ok';
        $detail = 'No critical or security update has been missing for more than ' . $days . ' days.';
        if ($n > 0) {
            $age = max(0, intdiv($this->sql->time() - Sql::ts((string) $overdue['oldest']), 86400));
            $status = $age >= 2 * $days ? 'fail' : 'warn';
            $detail = $n . ' critical or security update' . ($n === 1 ? '' : 's') . ' missing for more than ' . $days . ' days (the oldest for ' . $age . ' days).';
        } elseif (Sql::ts((string) $scan['scanned_at']) < $this->sql->time() - 3 * (int) $limits['patch_scan_interval_s']) {
            $status = 'warn';
            $detail = 'The last successful patch scan is more than three scan intervals old.';
        }
        $out['compliance'] = $status;
        for ($i = 0, $c = $status === 'ok' ? $okN : $failN; $i < $c; ++$i) {
            $results[] = ['key' => PatchProtocol::CHECK_COMPLIANCE, 'status' => $status, 'detail' => $detail, 'at' => $at];
        }

        $failed = $this->sql->all("SELECT title, update_id FROM rmm_patch_available WHERE device_id = ? AND status = 'failed' ORDER BY status_at DESC LIMIT 3", [$deviceId]);
        $failedTotal = (int) $this->sql->val("SELECT COUNT(*) FROM rmm_patch_available WHERE device_id = ? AND status = 'failed'", [$deviceId]);
        if ($failedTotal > 0) {
            $names = implode('; ', array_map(static fn (array $r): string => mb_substr((string) ($r['title'] !== '' ? $r['title'] : $r['update_id']), 0, 80), $failed));
            $detail2 = mb_substr($failedTotal . ' update' . ($failedTotal === 1 ? '' : 's') . ' failed to install: ' . $names, 0, 400);
            $out['install_failed'] = 'fail';
            for ($i = 0; $i < $failN; ++$i) {
                $results[] = ['key' => PatchProtocol::CHECK_INSTALL_FAILED, 'status' => 'fail', 'detail' => $detail2, 'at' => $at];
            }
        } elseif ($this->sql->one("SELECT 1 AS x FROM endpoint_agent_checks WHERE device_id = ? AND check_key = ? AND status IN ('warn','fail')", [$deviceId, PatchProtocol::CHECK_INSTALL_FAILED]) !== null) {
            $out['install_failed'] = 'ok';
            for ($i = 0; $i < $okN; ++$i) {
                $results[] = ['key' => PatchProtocol::CHECK_INSTALL_FAILED, 'status' => 'ok', 'detail' => 'No update is marked failed.', 'at' => $at];
            }
        }
        $this->checks->apply($dev, $results);

        return $out;
    }
}
