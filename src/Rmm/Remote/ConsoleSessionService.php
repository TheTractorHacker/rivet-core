<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Authz\RmmPrincipal;

/**
 * Mints the single-use redeem code of a Rivet Console launch. It is called by {@see \RivetCore\Rmm\Technician\TechnicianActions::launchRemote()} only AFTER every existing check
 * passed (ability, switches, policy, device scope) and the stock Mesh launch succeeded, and only while the `remote_console` switch is on: a denied launch mints nothing.
 *
 * The code (32 random bytes, base64url) is returned once, inside the deep link; the database keeps its SHA-256 only, with the technician, the device, the node, the kind
 * (`desktop`, `terminal`, `terminal-ps` or `files`), the mode (always `standard`: Background and chat are never issued), the launch's session id, a salted hash of the
 * technician's address and a 60 second expiry.
 *
 * @api
 */
final class ConsoleSessionService
{
    public function __construct(private readonly ConsoleStore $store, private readonly ConsoleSettings $settings, private readonly ?\RivetCore\Rmm\Contracts\BackgroundModeConfigInterface $policy = null)
    {
    }

    /** The refusal text of a client whose policy is `approve` (the same at launch and at redeem). */
    public const APPROVAL_MESSAGE = 'This client requires approval before a remote session can start.';

    /**
     * True when Background Mode's policy mode is `per_client` and the client's policy is `approve`: Core cannot arrange the approval, so a console launch is refused (a lookup that
     * fails does not refuse here; the redeem re-checks and denies on failure).
     */
    public function approvalRequired(int $clientId): bool
    {
        try {
            return $this->policy !== null && $this->policy->policyMode() === BackgroundModePolicy::MODE_PER_CLIENT && $this->policy->clientPolicy($clientId) === BackgroundModePolicy::CLIENT_APPROVE;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The configured origin, or null when `console_public_url` is not set. */
    public function origin(): ?string
    {
        $u = $this->settings->get()['public_url'];

        return $u === '' ? null : $u;
    }

    /**
     * The node as the console is told it: `node/<mesh domain>/<id>`, built from the stored node's id and the configured Mesh domain (so the domain inside `node` always agrees
     * with `mesh.domain` and `mesh.userid`). Null when the result is not an acceptable node id.
     */
    public static function consoleNode(string $storedNode, string $meshDomain): ?string
    {
        $id = (string) preg_replace('#^node/[^/]*/#', '', $storedNode);
        $node = 'node/' . $meshDomain . '/' . $id;

        return ConsoleLink::validNode($node) ? $node : null;
    }

    /**
     * Mint a code and build the `console` object of the launch result.
     *
     * @param array<string,mixed> $dev the device row
     * @return array{url:string,fallback_url:string}|null null when nothing could be minted (no origin, an unacceptable kind or node)
     */
    public function issue(RmmPrincipal $who, array $dev, string $kind, string $storedNode, string $meshDomain, string $sessionId, string $fallbackUrl, ?string $ip): ?array
    {
        $origin = $this->origin();
        $node = self::consoleNode($storedNode, $meshDomain);
        if ($origin === null || $node === null || !ConsoleLink::validKind($kind) || preg_match('/^[0-9a-f]{16}$/', $sessionId) !== 1) {
            return null;
        }
        $code = ConsoleLink::newCode();
        $desc = ConsoleLink::descriptor($origin, $code, $fallbackUrl, $this->settings->allowsInsecureHttp());
        if ($desc === null) {
            return null;
        }
        $this->store->insertCode(ConsoleLink::hashCode($code), $who->userId, ConsoleText::name($who->userName), (int) $dev['device_id'], (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0),
            $node, $kind, 'standard', $sessionId, ConsoleStore::ipHash($ip));

        return $desc;
    }
}
