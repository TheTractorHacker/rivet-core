<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Rmm\Authz\RmmAbility;

/**
 * A test AccessPolicy that models RivetIT's role rules (src/EndpointAgent/Authz.php) over a handful of in-memory users, so the
 * authorization matrix of tests/endpoint_agent_authz.php can be asserted against Core's RmmAuthorizer and TechnicianApi:
 *
 *   device.view                  module_rmm >= 1
 *   job.run_saved, job.reboot    module_rmm_scripts >= 2, not a module-only login
 *   job.run_script               module_rmm_scripts >= 3, not a module-only login
 *   remote.launch                module_rmm_remote_connect >= 1, not a module-only login
 *   admin-class abilities        role_is_admin
 *
 * Client scope is NOT in here (it is the tenancy's job), and an inactive account is denied everything. Unknown abilities are denied.
 */
final class RoleMatrixPolicy implements AccessPolicyInterface
{
    /** @var array<int,array{rmm:int,scripts:int,remote:int,admin:bool,limited:bool,active:bool}> */
    private array $users = [];

    public function addUser(int $id, int $rmm = 0, int $scripts = 0, int $remote = 0, bool $admin = false, bool $limited = false, bool $active = true): void
    {
        $this->users[$id] = ['rmm' => $rmm, 'scripts' => $scripts, 'remote' => $remote, 'admin' => $admin, 'limited' => $limited, 'active' => $active];
    }

    public function deactivate(int $id): void
    {
        $this->users[$id]['active'] = false;
    }

    public function activate(int $id): void
    {
        $this->users[$id]['active'] = true;
    }

    public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool
    {
        $u = $userId === null ? null : ($this->users[$userId] ?? null);
        if ($u === null || !$u['active']) {
            return false;
        }
        if ($u['admin']) {
            return in_array($ability, RmmAbility::all(), true);
        }
        if (RmmAbility::isAdministrative($ability)) {
            return false;
        }
        if ($u['rmm'] < 1) {
            return false;
        }

        return match ($ability) {
            RmmAbility::DEVICE_VIEW => true,
            RmmAbility::JOB_RUN_SAVED, RmmAbility::JOB_REBOOT => !$u['limited'] && $u['scripts'] >= 2,
            RmmAbility::JOB_RUN_SCRIPT => !$u['limited'] && $u['scripts'] >= 3,
            RmmAbility::REMOTE_LAUNCH => !$u['limited'] && $u['remote'] >= 1,
            default => false,
        };
    }
}
