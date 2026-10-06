<?php

declare(strict_types=1);

namespace RivetCore\Mcp;

use RivetCore\Database\DatabaseInterface;

/** Links a pending OAuth identity to one agent. Linking is always an explicit administrator action.
 *
 * Logging: $logError accepts a PSR-3 LoggerInterface (preferred) or null (ErrorLogLogger). The Closure form (receives the
 * message string) is @deprecated: kept working through all of 1.x, removed in 2.0.
 *
 * @api
 */
final class IdentityLinker
{
    public function __construct(
        private DatabaseInterface $database,
        private UnlinkedIdentityStore $store,
        private AgentDirectoryInterface $agents,
        /** @deprecated the Closure form; pass a PSR-3 LoggerInterface (kept through 1.x, removed in 2.0) */
        private \Closure|\Psr\Log\LoggerInterface|null $logError = null,
    ) {
    }

    /** @return array{0:bool,1:string} [ok, message] */
    public function link(int $pendingId, int $userId): array
    {
        try {
            return $this->database->transaction(function () use ($pendingId, $userId): array {
                $pending = $this->store->find($pendingId, true);
                if (!$pending) {
                    return [false, 'That sign-in is no longer waiting. Ask the person to try again.'];
                }
                $agent = $this->agents->findActiveAgent($userId);
                if (!$agent) {
                    return [false, 'Choose an active agent.'];
                }
                if ($agent['linked']) {
                    return [false, 'That agent is already linked. Unlink them first.'];
                }
                if ($this->agents->identityTaken($pending['issuer'], $pending['subject'])) {
                    return [false, 'This identity is already linked to another account.'];
                }
                $this->agents->link($userId, $pending['issuer'], $pending['subject']);
                $this->store->dismiss($pendingId);

                return [true, 'Linked.'];
            });
        } catch (\Throwable $e) {
            \RivetCore\Support\ErrorLogLogger::resolve($this->logError)->error('MCP link failed: ' . $e->getMessage());

            return [false, 'Could not link. Nothing was changed.'];
        }
    }
}
