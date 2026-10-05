<?php

declare(strict_types=1);

namespace RivetCore\Mcp;

use RivetCore\Database\DatabaseInterface;

/** Links a pending OAuth identity to one agent. Linking is always an explicit administrator action. */
final class IdentityLinker
{
    public function __construct(
        private DatabaseInterface $database,
        private UnlinkedIdentityStore $store,
        private AgentDirectoryInterface $agents,
        private ?\Closure $logError = null,
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
            ($this->logError ?? static function (string $m): void {
                error_log($m);
            })('MCP link failed: ' . $e->getMessage());

            return [false, 'Could not link. Nothing was changed.'];
        }
    }
}
