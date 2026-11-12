<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Contracts\BackgroundModeConfigInterface;
use RivetCore\Rmm\Support\Sql;

/**
 * Background Mode's policy settings in the Core tables of migration 0050: the one settings row (`rmm_background_settings`: mode and the named internal group) and the
 * per-client policy (`rmm_client_remote_policy`). Reads never create anything and an absent row reads as "not configured" (which denies). The setters validate and return
 * false on a refused value; they do not check who is asking and do not audit. Change settings through {@see BackgroundSettingsService} (admin check, audit with old and
 * new value, fail closed); the raw setters are internal to Core.
 *
 * @api
 */
final class DbBackgroundModeConfig implements BackgroundModeConfigInterface
{
    /** Letters, digits, space and `._-`, 1 to 100 characters. */
    public const GROUP_PATTERN = '/^[\p{L}\p{N} ._-]{1,100}$/u';

    /** @var array<string,mixed>|null */
    private ?array $row = null;

    public function __construct(private readonly Sql $sql)
    {
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return $this->row ??= ($this->sql->one('SELECT policy_mode, internal_group FROM rmm_background_settings WHERE id = 1') ?? []);
    }

    public function policyMode(): string
    {
        $m = (string) ($this->row()['policy_mode'] ?? '');

        return in_array($m, BackgroundModePolicy::MODES, true) ? $m : '';
    }

    public function internalGroup(): string
    {
        return trim((string) ($this->row()['internal_group'] ?? ''));
    }

    public function clientPolicy(int $clientId): ?string
    {
        $p = $this->sql->val('SELECT background_policy FROM rmm_client_remote_policy WHERE client_id = ?', [$clientId]);

        return is_string($p) && in_array($p, BackgroundModePolicy::CLIENT_POLICIES, true) ? $p : null;
    }

    /** @internal no caller check, no audit: use {@see BackgroundSettingsService::setPolicyMode()} */
    public function setPolicyMode(string $mode, int $userId): bool
    {
        if (!in_array($mode, BackgroundModePolicy::MODES, true)) {
            return false;
        }
        $this->upsertSetting('policy_mode', $mode, $userId);

        return true;
    }

    /** Name of the role or group Internal mode allows besides site administrators; an empty string clears it. Letters, digits, space and `._-` only, at most 100. @internal no caller check, no audit: use {@see BackgroundSettingsService::setInternalGroup()} */
    public function setInternalGroup(string $group, int $userId): bool
    {
        $group = trim($group);
        if ($group !== '' && preg_match(self::GROUP_PATTERN, $group) !== 1) {
            return false;
        }
        $this->upsertSetting('internal_group', $group, $userId);

        return true;
    }

    /** Set the policy of one client; null removes it (the client is then denied). @internal no caller check, no audit: use {@see BackgroundSettingsService::setClientPolicy()} */
    public function setClientPolicy(int $clientId, ?string $policy, int $userId): bool
    {
        if ($clientId <= 0 || ($policy !== null && !in_array($policy, BackgroundModePolicy::CLIENT_POLICIES, true))) {
            return false;
        }
        if ($policy === null) {
            $this->sql->run('DELETE FROM rmm_client_remote_policy WHERE client_id = ?', [$clientId]);

            return true;
        }
        $this->sql->run('INSERT INTO rmm_client_remote_policy (client_id, background_policy, updated_by, updated_at) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE background_policy = VALUES(background_policy), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)', [$clientId, $policy, $userId, $this->sql->utcNow()]);

        return true;
    }

    private function upsertSetting(string $column, string $value, int $userId): void
    {
        // $column is one of two literals chosen above, never caller input.
        $this->sql->run("INSERT INTO rmm_background_settings (id, $column, updated_by, updated_at) VALUES (1, ?, ?, ?) ON DUPLICATE KEY UPDATE $column = VALUES($column), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
            [$value, $userId, $this->sql->utcNow()]);
        $this->row = null;
    }
}
