<?php

declare(strict_types=1);

namespace RivetCore\Rmm\AutoInstall;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Deploy\DeployProtocol;
use RivetCore\Rmm\Deploy\HostedFiles;
use RivetCore\Rmm\Deploy\PackageCatalog;
use RivetCore\Rmm\Deploy\PackageParams;
use RivetCore\Rmm\Deploy\SoftwareTemplates;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * What an administrator or technician does with "install apps when the agent installs", shared by the REST API ({@see \RivetCore\Rmm\Http\AutoInstallApi}) and an
 * edition's pages. The same order of decisions as {@see \RivetCore\Rmm\Deploy\DeployActions}: the role cannot view devices or software -> 403; a device or client outside
 * the caller's clients is the same 404 as a missing one; no ability -> 403 (audited); the `software_deploy` switch is off -> 409 `feature_disabled` for writes (reads
 * still answer); then strict validation.
 *
 * ABILITIES. `rmm.software.view` reads the policies, the parameter NAMES (never a value), the hosted files and a device's app status. `rmm.software.manage` uploads
 * and deletes hosted installer files and sets or clears vendor parameters. `rmm.software.deploy` changes the auto-install policy of a package (it makes machines install
 * software by themselves later, like an assignment) and ticks apps on an installer. Everything fleet-wide (policies, global parameters, files) also needs a scope that
 * sees every client; a parameter for one client or location needs only that client.
 *
 * @api
 */
final class AutoInstallActions
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly RmmAuthorizer $authz,
        private readonly RmmSettings $settings,
        private readonly PackageCatalog $catalog,
        private readonly PackageParams $params,
        private readonly HostedFiles $hosted,
        private readonly AutoInstallService $auto,
        private readonly RmmAuditInterface $audit,
    ) {
    }

    private function role(RmmPrincipal $who, string $ability, int $clientId = 0): ?ActionResult
    {
        foreach ([RmmAbility::DEVICE_VIEW, RmmAbility::SOFTWARE_VIEW, $ability] as $a) {
            $d = $this->authz->check($who->userId, $a, $clientId);
            if ($d !== null) {
                if ($a === $ability && $ability !== RmmAbility::SOFTWARE_VIEW) {
                    $this->audit->record('Software Denied', "User {$who->userId} denied $ability: $d", $clientId, 0);
                }

                return ActionResult::fail(403, 'forbidden', $d);
            }
        }

        return null;
    }

    private function unrestricted(RmmPrincipal $who): ?ActionResult
    {
        return $this->authz->visibleClientIds($who->userId) === null ? null : ActionResult::fail(403, 'forbidden', 'This applies to every client; ask an administrator whose access is not limited to some clients.');
    }

    private function featureOff(): ?ActionResult
    {
        return $this->settings->featureOn(DeployProtocol::FEATURE) ? null : ActionResult::fail(409, 'feature_disabled', 'Software deployment is switched off. An administrator can turn it on in the RMM settings.');
    }

    /** @param \Closure():ActionResult $f */
    private function guard(\Closure $f): ActionResult
    {
        try {
            return $f();
        } catch (\InvalidArgumentException $e) {
            return ActionResult::fail(422, 'invalid', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ policies

    /** Every package with its auto-install policy and the vendor parameter names (set or not, never the values). */
    public function overview(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::SOFTWARE_VIEW)) !== null) {
            return $e;
        }

        $visible = $this->authz->visibleClientIds($who->userId);

        return ActionResult::ok('ok', 200, 'ok', ['enabled' => $this->settings->featureOn(DeployProtocol::FEATURE), 'packages' => array_map(fn (array $p): array => $this->limitedTo($p, $visible), $this->auto->packages())]);
    }

    /**
     * A package row as a caller who sees only some clients may read it (security review F6): the clients and locations a policy targets, and the client and location scopes
     * of the vendor parameters that are set, are cut down to the caller's clients, so another client's id is not disclosed. The location of a parameter does not say which
     * client it belongs to, so a restricted caller does not see location scopes at all. `null` = the caller sees every client: nothing is cut.
     *
     * @param array<string,mixed> $p
     * @param list<int>|null $visible
     * @return array<string,mixed>
     */
    private function limitedTo(array $p, ?array $visible): array
    {
        if ($visible === null) {
            return $p;
        }
        if (isset($p['auto']['clients']) && is_array($p['auto']['clients'])) {
            $p['auto']['clients'] = array_values(array_filter($p['auto']['clients'], static fn (array $c): bool => in_array((int) $c['client_id'], $visible, true)));
        }
        if (isset($p['params']['set']) && is_array($p['params']['set'])) {
            $p['params']['set'] = $this->setLimitedTo($p['params']['set'], $visible);
        }

        return $p;
    }

    /**
     * @param list<array<string,mixed>> $set
     * @param list<int> $visible
     * @return list<array<string,mixed>>
     */
    private function setLimitedTo(array $set, array $visible): array
    {
        return array_values(array_filter($set, static fn (array $r): bool => $r['scope'] === 'global' || ($r['scope'] === 'client' && in_array((int) $r['scope_id'], $visible, true))));
    }

    /**
     * @param array<string,mixed> $in {@see AutoInstallService::setAuto()}
     */
    public function setAuto(RmmPrincipal $who, int $packageId, array $in): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::SOFTWARE_DEPLOY) ?? $this->unrestricted($who) ?? $this->featureOff()) !== null) {
            return $e;
        }
        $pkg = $this->catalog->package($packageId);
        if ($pkg === null) {
            return ActionResult::fail(404, 'not_found', 'Package not found.');
        }

        return $this->guard(function () use ($who, $packageId, $in, $pkg): ActionResult {
            $s = $this->auto->setAuto($packageId, $in, $who->userId);
            $this->audit->record('Software Auto-install Set', "{$who->userName} set package \"" . mb_substr((string) $pkg['name'], 0, 80) . "\" (#$packageId) to install on new devices: scope {$s['scope']}"
                . ($s['scope'] === 'os' ? " ({$s['os_filter']})" : '') . ($s['scope'] === 'clients' ? ', ' . count($s['clients']) . ' client(s)' : '') . ", order {$s['install_order']}, " . ($s['required'] ? 'required' : 'best effort')
                . ($pkg['two_person'] ? '; each install still needs a second person to approve it' : ''), 0, 0);

            return ActionResult::ok($s['scope'] === 'off' ? 'Saved. New devices no longer install this automatically.' : 'Saved. New devices install this after the agent installs.', 200, 'saved', $s);
        });
    }

    /** The installer page's helper: which apps can be ticked for a new PC of this client, location and OS, and which are pre-ticked. */
    public function selectable(RmmPrincipal $who, int $clientId, int $locationId, string $os): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::SOFTWARE_VIEW, $clientId)) !== null) {
            return $e;
        }
        if ($clientId <= 0 || !$this->authz->clientOk($who->userId, $clientId)) {
            return ActionResult::fail(404, 'not_found', 'Client not found.');
        }
        if (!in_array($os, ['windows', 'linux', ''], true)) {
            return ActionResult::fail(422, 'invalid', 'os is windows or linux.');
        }

        return ActionResult::ok('ok', 200, 'ok', ['enabled' => $this->settings->featureOn(DeployProtocol::FEATURE), 'apps' => $this->auto->selectable($clientId, $locationId, $os)]);
    }

    /** May this user tick apps for an installer of this client (for the edition's issue action)? Null when yes, else the refusal. */
    public function mayTick(RmmPrincipal $who, int $clientId): ?string
    {
        $d = $this->authz->check($who->userId, RmmAbility::SOFTWARE_DEPLOY, $clientId);

        return $d ?? ($this->authz->clientOk($who->userId, $clientId) ? null : 'Client not found.');
    }

    // ------------------------------------------------------------------ starter templates

    /** The starter templates (data only; every one is marked unverified) and what each needs from you. */
    public function templates(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::SOFTWARE_VIEW)) !== null) {
            return $e;
        }

        return ActionResult::ok('ok', 200, 'ok', ['templates' => SoftwareTemplates::all(), 'you_provide' => SoftwareTemplates::PROVIDES]);
    }

    /**
     * Create a package (or add a version to one) from a starter template. The template gives the format and the suggested arguments; you give the version, the
     * installer (an address on an allowed host, or an uploaded file), its hash and size, and the detection rule. Everything goes through the same checks as a version added by
     * hand; the arguments may be replaced (they are checked the same way).
     *
     * @param array<string,mixed> $in {name?, package_id?, publisher?, version, url?|hosted_file_id?, sha256?, size_bytes?, silent_args?, detect_kind?, detect_value?, detect_min?, product_code?, pkg_name?, pin_version?}
     */
    public function fromTemplate(RmmPrincipal $who, string $key, array $in): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::SOFTWARE_MANAGE) ?? $this->unrestricted($who) ?? $this->featureOff()) !== null) {
            return $e;
        }
        $t = SoftwareTemplates::find($key);
        if ($t === null) {
            return ActionResult::fail(404, 'not_found', 'No such template.');
        }

        return $this->guard(function () use ($who, $t, $in): ActionResult {
            $ver = array_diff_key($in, array_flip(['name', 'package_id', 'publisher']));
            $ver['os'] = $t['os'];
            $ver['fmt'] = $t['fmt'];
            if ($t['fmt'] === 'exe') {
                $ver['silent_args'] ??= $t['silent_args'];
            }
            $pid = $in['package_id'] ?? null;
            if ($pid !== null) {
                if (!is_int($pid) || $this->catalog->package($pid) === null) {
                    throw new \InvalidArgumentException('package_id must name an existing package.');
                }
            }
            // Check the version first so a refusal leaves no half-made package behind.
            $created = false;
            if ($pid === null) {
                $pkg = $this->catalog->savePackage(null, ['name' => $in['name'] ?? $t['name'], 'publisher' => $in['publisher'] ?? $t['publisher'], 'description' => 'From the starter template "' . $t['name'] . '" (unverified)'], $who->userId);
                $pid = (int) $pkg['package_id'];
                $created = true;
            }
            try {
                $v = $this->catalog->addVersion((int) $pid, $ver, $who->userId);
            } catch (\InvalidArgumentException $e) {
                if ($created) {
                    $this->catalog->deletePackage((int) $pid);
                }
                throw $e;
            }
            $this->audit->record('Software Template Used', "{$who->userName} created version {$v['version']} of package #$pid from the starter template \"{$t['key']}\" (unverified)", 0, 0);

            return ActionResult::ok('Saved. This template is unverified: run a dry run, then one pilot machine, before relying on it.', 201, 'saved', ['package_id' => (int) $pid, 'version' => $v,
                'parameters_to_set' => array_map(static fn (array $p): string => (string) $p['name'], $t['placeholders'])]);
        });
    }

    // ------------------------------------------------------------------ vendor parameters

    /** Where each parameter of a package is set. Never a value. */
    public function paramsOf(RmmPrincipal $who, int $packageId): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::SOFTWARE_VIEW)) !== null) {
            return $e;
        }
        if ($this->catalog->package($packageId) === null) {
            return ActionResult::fail(404, 'not_found', 'Package not found.');
        }

        $set = $this->params->status($packageId);
        $visible = $this->authz->visibleClientIds($who->userId);

        return ActionResult::ok('ok', 200, 'ok', ['declared' => $this->params->declared($packageId), 'set' => $visible === null ? $set : $this->setLimitedTo($set, $visible), 'values_are_never_shown' => true]);
    }

    /**
     * @param array<string,mixed> $in {name, value, scope?: global (default)|client|location, scope_id?}
     */
    public function setParam(RmmPrincipal $who, int $packageId, array $in): ActionResult
    {
        return $this->paramWrite($who, $packageId, $in, true);
    }

    /**
     * @param array<string,mixed> $in {name, scope?, scope_id?}
     */
    public function clearParam(RmmPrincipal $who, int $packageId, array $in): ActionResult
    {
        return $this->paramWrite($who, $packageId, $in, false);
    }

    /** @param array<string,mixed> $in */
    private function paramWrite(RmmPrincipal $who, int $packageId, array $in, bool $set): ActionResult
    {
        $scope = $in['scope'] ?? 'global';
        $scopeId = $in['scope_id'] ?? 0;
        if (!is_string($scope) || !is_int($scopeId)) {
            return ActionResult::fail(422, 'invalid', 'scope is global, client or location; scope_id is a whole number.');
        }
        $allowed = $set ? ['name', 'value', 'scope', 'scope_id'] : ['name', 'scope', 'scope_id'];
        if (array_diff(array_keys($in), $allowed) !== []) {
            return ActionResult::fail(422, 'invalid', 'Unknown field.');
        }
        // A client parameter needs that client; a location parameter is checked against its client by the edition's tenancy through the caller's visible clients; global needs the whole fleet.
        if ($scope === 'global') {
            $e = $this->role($who, RmmAbility::SOFTWARE_MANAGE) ?? $this->unrestricted($who);
        } elseif ($scope === 'client') {
            $e = $scopeId <= 0 || !$this->authz->clientOk($who->userId, $scopeId) ? ActionResult::fail(404, 'not_found', 'Client not found.') : $this->role($who, RmmAbility::SOFTWARE_MANAGE, $scopeId);
        } elseif ($scope === 'location') {
            $e = $this->role($who, RmmAbility::SOFTWARE_MANAGE) ?? $this->unrestricted($who);   // a location id alone does not say which client it belongs to
        } else {
            $e = ActionResult::fail(422, 'invalid', 'scope is global, client or location.');
        }
        if ($e !== null || ($e = $this->featureOff()) !== null) {
            return $e;
        }
        $pkg = $this->catalog->package($packageId);
        if ($pkg === null) {
            return ActionResult::fail(404, 'not_found', 'Package not found.');
        }

        return $this->guard(function () use ($who, $packageId, $in, $set, $scope, $scopeId, $pkg): ActionResult {
            $name = $in['name'] ?? null;
            if ($set) {
                $this->params->set($packageId, $scope, $scopeId, $name, $in['value'] ?? null, $who->userId);
            } elseif (!$this->params->clear($packageId, $scope, $scopeId, $name)) {
                return ActionResult::fail(404, 'not_found', 'That parameter is not set there.');
            }
            // The audit row names the parameter and where; the value is neither here nor anywhere else after it was sealed.
            $this->audit->record($set ? 'Software Parameter Set' : 'Software Parameter Cleared', "{$who->userName} " . ($set ? 'set' : 'cleared') . ' parameter "' . (is_string($name) ? $name : '?') . '" of package "'
                . mb_substr((string) $pkg['name'], 0, 80) . "\" ($scope" . ($scopeId > 0 ? " #$scopeId" : '') . ')', $scope === 'client' ? $scopeId : 0, 0);

            return ActionResult::ok($set ? 'Saved. The value is sealed and is not shown again.' : 'Cleared.', 200, 'saved', ['declared' => $this->params->declared($packageId), 'set' => $this->params->status($packageId)]);
        });
    }

    // ------------------------------------------------------------------ hosted files

    public function files(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::SOFTWARE_VIEW)) !== null) {
            return $e;
        }

        return ActionResult::ok('ok', 200, 'ok', ['files' => $this->hosted->files(), 'max_bytes' => $this->hosted->maxBytes()]);
    }

    /**
     * Who may upload, answered BEFORE any byte of the body is read: the failure to return (403 or the feature switch), or null when the caller may go on. An edition that
     * receives the body itself must call this first, so a caller who may only view devices cannot make the server write a 512 MiB temporary file for a 403.
     */
    public function uploadGate(RmmPrincipal $who): ?ActionResult
    {
        return $this->role($who, RmmAbility::SOFTWARE_MANAGE) ?? $this->unrestricted($who) ?? $this->featureOff();
    }

    /** Store an uploaded installer (the edition passes the temporary file of its upload handler). */
    public function uploadFile(RmmPrincipal $who, string $tmpPath, string $originalName): ActionResult
    {
        if (($e = $this->uploadGate($who)) !== null) {
            return $e;
        }
        $r = $this->hosted->store($tmpPath, $originalName, $who->userId);
        if (!$r['ok']) {
            return ActionResult::fail(422, 'invalid', (string) ($r['error'] ?? 'The file was refused.'));
        }

        return ActionResult::ok('Stored. Add a version of a package that points at it.', 201, 'saved', $r['file'] ?? []);
    }

    public function deleteFile(RmmPrincipal $who, int $fileId): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::SOFTWARE_MANAGE) ?? $this->unrestricted($who) ?? $this->featureOff()) !== null) {
            return $e;
        }
        $why = $this->hosted->delete($fileId);
        if ($why === 'not_found') {
            return ActionResult::fail(404, 'not_found', 'File not found.');
        }
        if ($why !== null) {
            return ActionResult::fail(409, 'conflict', 'A package version uses that file; it cannot be deleted.');
        }
        $this->audit->record('Hosted Installer Deleted', "{$who->userName} deleted hosted installer #$fileId", 0, 0);

        return ActionResult::ok('Deleted.', 200, 'deleted');
    }

    // ------------------------------------------------------------------ per device

    /** The apps a device was told to install with the agent and where each stands (queued, installing, installed, failed, skipped, with the reason). */
    public function deviceApps(RmmPrincipal $who, int $deviceId): ActionResult
    {
        foreach ([RmmAbility::DEVICE_VIEW, RmmAbility::SOFTWARE_VIEW] as $a) {
            $d = $this->authz->check($who->userId, $a, 0);
            if ($d !== null) {
                return ActionResult::fail(403, 'forbidden', $d);
            }
        }
        $dev = $this->devices->find($deviceId);
        $client = $dev === null ? 0 : (int) $dev['client_id'];
        if ($dev === null || !$this->authz->clientOk($who->userId, $client) || $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, $client) !== null
            || $this->authz->check($who->userId, RmmAbility::SOFTWARE_VIEW, $client) !== null) {
            return ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $apps = $this->auto->deviceApps($deviceId);
        $counts = array_fill_keys(AutoInstallService::STATUSES, 0);
        foreach ($apps as $a) {
            $counts[$a['status']]++;
        }

        return ActionResult::ok('ok', 200, 'ok', ['enabled' => $this->settings->featureOn(DeployProtocol::FEATURE), 'counts' => $counts, 'apps' => $apps]);
    }
}
