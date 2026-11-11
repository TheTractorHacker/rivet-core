<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * The only supported way to change Background Mode's policy (mode, internal group, per-client policy). It requires `rmm.admin` for the caller, validates, writes ONE audit
 * row that names the administrator and records the old and the new value, and only then changes the setting: if the audit write throws, nothing was changed (fail closed).
 * A refused change (not an administrator, invalid value) is audited as `Background Settings Denied` and changes nothing. There is no setting that switches this audit off.
 * After a change, `rmm.background.policy_changed` is published (old and new value, no secret).
 *
 * Edition code must call this and not the raw setters of {@see DbBackgroundModeConfig}, which neither check the caller nor audit.
 *
 * @api
 */
final class BackgroundSettingsService
{
    public function __construct(
        private readonly RmmAuthorizer $authz,
        private readonly DbBackgroundModeConfig $config,
        private readonly RmmAuditInterface $audit,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    /** `internal`, `per_client` (see {@see BackgroundModePolicy::MODES}). */
    public function setPolicyMode(RmmPrincipal $who, string $mode): ActionResult
    {
        if (!in_array($mode, BackgroundModePolicy::MODES, true)) {
            return $this->refuse($who, 'policy_mode', 'the mode must be internal or per_client');
        }

        return $this->change($who, 'policy_mode', 0, $this->config->policyMode(), $mode, fn (): bool => $this->config->setPolicyMode($mode, $who->userId));
    }

    /** The one role or group Internal mode allows besides administrators; an empty string clears it. */
    public function setInternalGroup(RmmPrincipal $who, string $group): ActionResult
    {
        $group = trim($group);
        if ($group !== '' && preg_match(DbBackgroundModeConfig::GROUP_PATTERN, $group) !== 1) {
            return $this->refuse($who, 'internal_group', 'the group name may hold letters, digits, space and ._- only, at most 100');
        }

        return $this->change($who, 'internal_group', 0, $this->config->internalGroup(), $group, fn (): bool => $this->config->setInternalGroup($group, $who->userId));
    }

    /** `allow`, `notify` or `approve`; null removes the client's policy (the client is then denied). */
    public function setClientPolicy(RmmPrincipal $who, int $clientId, ?string $policy): ActionResult
    {
        if ($clientId <= 0 || ($policy !== null && !in_array($policy, BackgroundModePolicy::CLIENT_POLICIES, true))) {
            return $this->refuse($who, 'client_policy', 'the client or the policy is not valid');
        }

        return $this->change($who, 'client_policy', $clientId, $this->config->clientPolicy($clientId), $policy, fn (): bool => $this->config->setClientPolicy($clientId, $policy, $who->userId));
    }

    /**
     * @param \Closure():bool $apply
     */
    private function change(RmmPrincipal $who, string $setting, int $clientId, ?string $old, ?string $new, \Closure $apply): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0, false);
        if ($denied !== null) {
            $this->audit->record('Background Settings Denied', $this->label($who) . " was denied a change of $setting" . ($clientId > 0 ? " for client $clientId" : '') . ": $denied", $clientId, 0);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        $oldText = $old === null || $old === '' ? 'none' : $old;
        $newText = $new === null || $new === '' ? 'none' : $new;
        // The audit row comes first and is not caught: no row, no change.
        $this->audit->record('Background Settings Changed', $this->label($who) . " changed $setting" . ($clientId > 0 ? " for client $clientId" : '') . ": '$oldText' -> '$newText'", $clientId, 0);
        try {
            $ok = $apply();
        } catch (\Throwable $e) {
            $this->audit->record('Background Settings Failed', $this->label($who) . " change of $setting did not complete: " . get_class($e), $clientId, 0);
            throw $e;
        }
        if (!$ok) {
            $this->audit->record('Background Settings Failed', $this->label($who) . " change of $setting was refused by the store", $clientId, 0);

            return ActionResult::fail(422, 'invalid', 'The value was not accepted.');
        }
        $this->events?->emitScoped(RmmEvent::BACKGROUND_POLICY_CHANGED, $clientId, ['setting' => $setting, 'client_id_changed' => $clientId, 'old_value' => $old === '' ? null : $old,
            'new_value' => $new === '' ? null : $new, 'changed_by' => $who->userId]);

        return ActionResult::ok('Saved.', 200, 'saved', ['setting' => $setting, 'old' => $old, 'new' => $new]);
    }

    private function refuse(RmmPrincipal $who, string $setting, string $why): ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0, false);
        if ($denied !== null) {
            $this->audit->record('Background Settings Denied', $this->label($who) . " was denied a change of $setting: $denied", 0, 0);

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
