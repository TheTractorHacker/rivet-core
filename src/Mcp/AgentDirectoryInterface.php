<?php

declare(strict_types=1);

namespace RivetCore\Mcp;

/**
 * The edition's view of its agents (staff users). Core links OAuth identities to agents through this
 * interface and never queries the edition's user table itself. Implementations run on the same connection as
 * the DatabaseInterface handed to IdentityLinker, so lookups inside link() join its transaction.
 */
interface AgentDirectoryInterface
{
    /** Active agents without a link yet. @return list<array{user_id:int|string, user_name:string, user_email:string}> */
    public function linkableAgents(): array;

    /** Agents that have an identity linked. @return list<array<string,mixed>> */
    public function linkedAgents(): array;

    public function linkedCount(): int;

    /**
     * An active agent, locked for update when inside a transaction, or null if there is no such agent.
     * @return array{linked:bool}|null
     */
    public function findActiveAgent(int $userId): ?array;

    /** Is this issuer+subject already linked to some agent? */
    public function identityTaken(string $issuer, string $subject): bool;

    public function link(int $userId, string $issuer, string $subject): void;

    public function unlink(int $userId): void;
}
