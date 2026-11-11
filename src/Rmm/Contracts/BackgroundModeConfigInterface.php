<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * What {@see \RivetCore\Rmm\Remote\BackgroundModePolicy} reads: the policy mode, the named internal group and the per-client policy. Core ships a database
 * implementation ({@see \RivetCore\Rmm\Remote\DbBackgroundModeConfig}); an edition may supply its own. An implementation that cannot answer must throw or return
 * the "not configured" value (empty string, null): the policy treats every missing, unknown or failing answer as DENY.
 *
 * @api
 */
interface BackgroundModeConfigInterface
{
    /** `internal`, `per_client`, or an empty string when nothing is configured (which denies). */
    public function policyMode(): string;

    /** The name of the one role or group (besides site administrators) that Internal mode allows; empty string for none. The edition decides what the name means. */
    public function internalGroup(): string;

    /** The per-client policy (`allow`, `notify`, `approve`) of one client, or null when none is stored (which denies). */
    public function clientPolicy(int $clientId): ?string;
}
