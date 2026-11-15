<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Contracts\RemoteSessionPolicyStoreInterface;
use RivetCore\Rmm\Support\Sql;

/**
 * The per-client remote-session policy in the Core table of migration 0050 (`rmm_client_remote_policy`). Reads never create anything. The setter validates and returns
 * false on a refused value; it does not check who is asking and does not audit. Change the policy through {@see RemoteSessionPolicySettingsService} (admin check, audit
 * with old and new value, fail closed); the raw setter is internal to Core.
 *
 * @api
 */
final class DbRemoteSessionPolicyStore implements RemoteSessionPolicyStoreInterface
{
    public function __construct(private readonly Sql $sql)
    {
    }

    public function clientPolicy(int $clientId): ?string
    {
        $p = $this->sql->val('SELECT policy FROM rmm_client_remote_policy WHERE client_id = ?', [$clientId]);

        // A stored value that is not one of the three is returned as it is: the policy denies it (fail closed) rather than reading it as "no policy".
        return is_string($p) ? $p : null;
    }

    /** Set the policy of one client; null removes it (no extra requirement). @internal no caller check, no audit: use {@see RemoteSessionPolicySettingsService::setClientPolicy()} */
    public function setClientPolicy(int $clientId, ?string $policy, int $userId): bool
    {
        if ($clientId <= 0 || ($policy !== null && !in_array($policy, RemoteSessionPolicy::CLIENT_POLICIES, true))) {
            return false;
        }
        if ($policy === null) {
            $this->sql->run('DELETE FROM rmm_client_remote_policy WHERE client_id = ?', [$clientId]);

            return true;
        }
        $this->sql->run('INSERT INTO rmm_client_remote_policy (client_id, policy, updated_by, updated_at) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE policy = VALUES(policy), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)', [$clientId, $policy, $userId, $this->sql->utcNow()]);

        return true;
    }
}
