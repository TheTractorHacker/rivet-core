<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Support\Sql;

/**
 * Housekeeping of the Background Mode session table (`rmm_background_sessions`): sessions that ENDED (closed, timed out, revoked, errored or a launch that failed to start)
 * more than `background_session_retention_days` days ago are deleted in batches, together with their check-token rows. A session that has not ended (active, or one whose
 * end was never reported) is never deleted, whatever its age. The audit log is a separate record with its own retention: nothing here reads or writes it, so the
 * `Background Session ...` audit rows outlive the session rows. Denied attempts have no session row at all.
 *
 * @api
 */
final class BackgroundSessionRetention
{
    public const BATCH = 1000;
    /** Rows one run may delete; the next run continues. */
    public const RUN_CAP = 20000;

    public function __construct(private readonly Sql $sql)
    {
    }

    /** @return array{sessions:int,tokens:int} rows deleted */
    public function prune(int $retentionDays): array
    {
        $before = $this->sql->utcAt(-max(1, $retentionDays) * 86400);
        $out = ['sessions' => 0, 'tokens' => 0];
        while ($out['sessions'] < self::RUN_CAP) {
            $ids = $this->sql->all('SELECT session_id FROM rmm_background_sessions WHERE ended_at IS NOT NULL AND ended_at < ? ORDER BY ended_at LIMIT ' . self::BATCH, [$before]);
            if ($ids === []) {
                break;
            }
            $keys = array_map(static fn (array $r): string => (string) $r['session_id'], $ids);
            $in = implode(',', array_fill(0, count($keys), '?'));
            $out['tokens'] += $this->sql->run("DELETE FROM rmm_background_tokens WHERE session_id IN ($in)", $keys);
            // ended_at is re-checked so a row can never be deleted unless it is still ended and old.
            $out['sessions'] += $this->sql->run("DELETE FROM rmm_background_sessions WHERE session_id IN ($in) AND ended_at IS NOT NULL AND ended_at < ?", [...$keys, $before]);
            if (count($keys) < self::BATCH) {
                break;
            }
        }

        return $out;
    }
}
