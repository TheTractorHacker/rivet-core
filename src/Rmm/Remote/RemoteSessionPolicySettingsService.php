<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * The only supported way to change a client's remote-session policy. It requires `rmm.admin` for the caller, validates, writes ONE audit row that names the administrator
 * and records the old and the new value, and only then changes the setting: if the audit write throws, nothing was changed (fail closed). A refused change (not an
 * administrator, invalid value) is audited as `Remote Session Policy Denied` and changes nothing. There is no setting that switches this audit off.
 *
 * Edition code must call this and not the raw setter of {@see DbRemoteSessionPolicyStore}, which neither checks the caller nor audits.
 *
 * @api
 */
final class RemoteSessionPolicySettingsService
{
    public function __construct(
        private readonly RmmAuthorizer $authz,
        private readonly DbRemoteSessionPolicyStore $store,
        private readonly RmmAuditInterface $audit,
    ) {
    }

    /** `allow`, `notify` or `approve`; null removes the client's policy (no extra requirement). */
    public function setClientPolicy(RmmPrincipal $who, int $clientId, ?string $policy): ActionResult
    {
        if ($clientId <= 0 || ($policy !== null && !in_array($policy, RemoteSessionPolicy::CLIENT_POLICIES, true))) {
            return $this->refuse($who, 'the client or the policy is not valid');
        }
        $denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0, false);
        if ($denied !== null) {
            $this->audit->record('Remote Session Policy Denied', $this->label($who) . " was denied a change of the remote session policy for client $clientId: $denied", $clientId, 0);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        $old = $this->store->clientPolicy($clientId);
        $oldText = $old === null || $old === '' ? 'none' : $old;
        $newText = $policy ?? 'none';
        // The audit row comes first and is not caught: no row, no change.
        $this->audit->record('Remote Session Policy Changed', $this->label($who) . " changed the remote session policy for client $clientId: '$oldText' -> '$newText'", $clientId, 0);
        try {
            $ok = $this->store->setClientPolicy($clientId, $policy, $who->userId);
        } catch (\Throwable $e) {
            $this->audit->record('Remote Session Policy Failed', $this->label($who) . " change of the remote session policy for client $clientId did not complete: " . get_class($e), $clientId, 0);
            throw $e;
        }
        if (!$ok) {
            $this->audit->record('Remote Session Policy Failed', $this->label($who) . " change of the remote session policy for client $clientId was refused by the store", $clientId, 0);

            return ActionResult::fail(422, 'invalid', 'The value was not accepted.');
        }

        return ActionResult::ok('Saved.', 200, 'saved', ['setting' => 'client_policy', 'old' => $old, 'new' => $policy]);
    }

    private function refuse(RmmPrincipal $who, string $why): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0, false);
        if ($denied !== null) {
            $this->audit->record('Remote Session Policy Denied', $this->label($who) . " was denied a change of the remote session policy: $denied", 0, 0);

            return ActionResult::fail(403, 'forbidden', $denied);
        }

        return ActionResult::fail(422, 'invalid', ucfirst($why) . '.');
    }

    private function label(RmmPrincipal $who): string
    {
        $n = trim((string) preg_replace('/[^\p{L}\p{N} ._@-]/u', '', $who->userName));

        return 'user ' . $who->userId . ' (' . ($n === '' ? 'unnamed' : mb_substr($n, 0, 60)) . ')';
    }
}
