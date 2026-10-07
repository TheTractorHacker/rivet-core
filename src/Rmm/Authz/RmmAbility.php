<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Authz;

/**
 * The abilities the RMM module asks an edition's {@see \RivetCore\Contracts\AccessPolicyInterface} about. The subject is always
 * `('client', $clientId)`; `$clientId = 0` is the role-level question ("may this user do this anywhere").
 *
 * What RivetIT's role matrix maps them to (src/EndpointAgent/Authz.php): `device.view` is `module_rmm >= 1`; `job.run_saved`
 * (also: collect, cancel a job, read job output) and `job.reboot` are `module_rmm_scripts >= 2`; `job.run_script` is
 * `module_rmm_scripts >= 3`; `remote.launch` is `module_rmm_remote_connect >= 1`; each of those also needs a non-module-only
 * login. `rmm.admin`, `rmm.device.manage`, `rmm.token.manage` and `rmm.binary.publish` are all `role_is_admin` there; an edition
 * may split them. A policy that enforces anything must deny an ability it does not know.
 *
 * @api
 */
final class RmmAbility
{
    /** See devices, their checks and job history. Every other device ability also needs this one. */
    public const DEVICE_VIEW = 'rmm.device.view';
    /** Approve or reject a pending device, link or create its asset, revoke, retire, transfer, re-enroll, rotate, set ring, map a MeshCentral node. */
    public const DEVICE_MANAGE = 'rmm.device.manage';
    /** Queue a saved-library script or a collect job, cancel a queued job, read job output. */
    public const JOB_RUN_SAVED = 'rmm.job.run_saved';
    /** Queue a reboot (always destructive). */
    public const JOB_REBOOT = 'rmm.job.reboot';
    /** Queue free-form script text. */
    public const JOB_RUN_SCRIPT = 'rmm.job.run_script';
    /** Open a remote session (MeshCentral). */
    public const REMOTE_LAUNCH = 'rmm.remote.launch';
    /** Create and revoke enrollment tokens, issue installers and deployment commands. */
    public const TOKEN_MANAGE = 'rmm.token.manage';
    /** Upload agent binaries, make one current, offer it as an update, manage releases and rings. */
    public const BINARY_PUBLISH = 'rmm.binary.publish';
    /** Module settings, switches, signing key, MeshCentral settings. */
    public const ADMIN = 'rmm.admin';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DEVICE_VIEW, self::DEVICE_MANAGE, self::JOB_RUN_SAVED, self::JOB_REBOOT, self::JOB_RUN_SCRIPT, self::REMOTE_LAUNCH,
            self::TOKEN_MANAGE, self::BINARY_PUBLISH, self::ADMIN];
    }

    /** Abilities of the administration side: they do not need the technician's `device.view` grant. */
    public static function isAdministrative(string $ability): bool
    {
        return in_array($ability, [self::ADMIN, self::DEVICE_MANAGE, self::TOKEN_MANAGE, self::BINARY_PUBLISH], true);
    }

    /** The safe reason shown when an ability is denied (generic: the edition's own wording is not part of the contract). */
    public static function denial(string $ability): string
    {
        return match ($ability) {
            self::DEVICE_VIEW => 'Your role cannot view RMM devices.',
            self::JOB_RUN_SCRIPT => 'Your role cannot run free-form PowerShell.',
            self::JOB_RUN_SAVED, self::JOB_REBOOT => 'Your role cannot run jobs on devices.',
            self::REMOTE_LAUNCH => 'Your role cannot open remote sessions.',
            self::ADMIN, self::DEVICE_MANAGE, self::TOKEN_MANAGE, self::BINARY_PUBLISH => 'Administrator access is required.',
            default => 'Unknown action.',
        };
    }
}
