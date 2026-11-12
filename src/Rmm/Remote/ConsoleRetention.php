<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Support\Sql;

/**
 * Housekeeping of the Rivet Console tables: redeem codes (`rmm_console_codes`) a day after they expired (used or not), and sessions (`rmm_console_sessions`, which hold the
 * hashed report token) once they ended more than `console_session_retention_days` days ago, or, when the end was never reported, once their token passed its 12 hour cap by that
 * many days. Deleted in batches. The audit log is a separate record with its own retention: nothing here reads or writes it.
 *
 * @api
 */
final class ConsoleRetention
{
    public const BATCH = 1000;
    public const RUN_CAP = 20000;
    /** How long an expired code row is kept. */
    public const CODE_KEEP_S = 86400;

    public function __construct(private readonly Sql $sql)
    {
    }

    /** @return array{codes:int,sessions:int} rows deleted */
    public function prune(int $retentionDays): array
    {
        $out = ['codes' => 0, 'sessions' => 0];
        $before = $this->sql->utcAt(-max(1, $retentionDays) * 86400);
        $codesBefore = $this->sql->utcAt(-self::CODE_KEEP_S);
        while ($out['codes'] < self::RUN_CAP) {
            $n = $this->sql->run('DELETE FROM rmm_console_codes WHERE expires_at < ? LIMIT ' . self::BATCH, [$codesBefore]);
            $out['codes'] += $n;
            if ($n < self::BATCH) {
                break;
            }
        }
        while ($out['sessions'] < self::RUN_CAP) {
            $n = $this->sql->run('DELETE FROM rmm_console_sessions WHERE (ended_at IS NOT NULL AND ended_at < ?) OR (ended_at IS NULL AND hard_expires_at < ?) LIMIT ' . self::BATCH, [$before, $before]);
            $out['sessions'] += $n;
            if ($n < self::BATCH) {
                break;
            }
        }

        return $out;
    }
}
