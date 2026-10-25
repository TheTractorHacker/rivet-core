<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

use RivetCore\Rmm\Support\Sql;

/**
 * A device moved to another client: its stored log entries and change records (which carry the client, phase 2 of #89) follow it. On MariaDB that is one more UPDATE
 * in the same database. When the hot store is another database that is down at that moment the move cannot be completed, and leaving the old client on the rows would
 * keep a client's technicians seeing the history of a device that is no longer theirs, so the move is RECORDED in the control database (`rmm_pending_client_moves`,
 * migration 0043) and the housekeeping job repeats it until it works. A later move of the same device replaces an earlier pending one.
 *
 * @internal
 */
final class ClientMoves
{
    public function __construct(private readonly Sql $sql, private readonly RmmDataStores $stores)
    {
    }

    /** Move the history of the device; if the hot store is unavailable, remember to do it later. */
    public function apply(int $deviceId, int $clientId): void
    {
        try {
            $this->stores->logs()->reassignClient($deviceId, $clientId);
            $this->stores->changes()->reassignClient($deviceId, $clientId);
        } catch (HotStoreUnavailableException) {
            try {
                $this->sql->run('INSERT INTO rmm_pending_client_moves (device_id, client_id, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), created_at = VALUES(created_at)',
                    [$deviceId, $clientId, $this->sql->utcNow()]);
            } catch (\Throwable $e) {
                error_log('rmm client move not recorded (migration 0043 not applied?): ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200));
            }
        }
    }

    /**
     * Repeat the moves that could not be made. Returns how many were completed.
     *
     * @param int $limit most moves one call tries
     */
    public function retryPending(int $limit = 100): int
    {
        try {
            $rows = $this->sql->all('SELECT device_id, client_id FROM rmm_pending_client_moves ORDER BY created_at, device_id LIMIT ' . max(1, $limit));
        } catch (\Throwable) {
            return 0;   // migration 0043 not applied yet
        }
        $done = 0;
        foreach ($rows as $r) {
            try {
                $this->stores->logs()->reassignClient((int) $r['device_id'], (int) $r['client_id']);
                $this->stores->changes()->reassignClient((int) $r['device_id'], (int) $r['client_id']);
            } catch (HotStoreUnavailableException) {
                break;   // still down: the next run tries again; the others would only fail the same way
            }
            // delete only the move that was made (a newer one for the same device may have replaced it meanwhile)
            $this->sql->run('DELETE FROM rmm_pending_client_moves WHERE device_id = ? AND client_id = ?', [(int) $r['device_id'], (int) $r['client_id']]);
            ++$done;
        }

        return $done;
    }
}
