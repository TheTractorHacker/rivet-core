<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * What {@see \RivetCore\Rmm\Remote\RemoteSessionPolicy} reads: the per-client remote-session policy. Core ships a database implementation
 * ({@see \RivetCore\Rmm\Remote\DbRemoteSessionPolicyStore}); an edition may supply its own. An implementation that cannot answer must throw: the policy treats a failing
 * lookup as DENY.
 *
 * @api
 */
interface RemoteSessionPolicyStoreInterface
{
    /** The per-client policy (`allow`, `notify`, `approve`) of one client, or null when none is stored (no extra requirement). */
    public function clientPolicy(int $clientId): ?string;
}
