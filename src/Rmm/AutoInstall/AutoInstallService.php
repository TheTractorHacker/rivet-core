<?php

declare(strict_types=1);

namespace RivetCore\Rmm\AutoInstall;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Deploy\ArgsTemplate;
use RivetCore\Rmm\Deploy\DeployApprovalService;
use RivetCore\Rmm\Deploy\DeployExpectations;
use RivetCore\Rmm\Deploy\DeployProtocol;
use RivetCore\Rmm\Deploy\DeployService;
use RivetCore\Rmm\Deploy\PackageCatalog;
use RivetCore\Rmm\Deploy\PackageParams;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * "Install apps when the agent installs" (1.0.0-rc.26): catalog packages marked to install on NEW devices, plus the apps a technician ticked when the installer was
 * issued, installed in order as ordinary signed `software_install` jobs ({@see DeployService::queueInstall()}: hash-pinned, allow-listed hosts, sealed vendor values).
 * Nothing about the device protocol changes; this class only decides WHICH catalog installs to queue, WHEN, and records WHY each one is where it is.
 *
 * THE RULES, in the order they are enforced:
 *  - the `software_deploy` switch is off (the default): nothing is planned, queued or stored;
 *  - PLANNING happens when a device enrolls ({@see planDevice()}): one `rmm_device_apps` row per (device, package), created with INSERT IGNORE, so a re-enrollment or a
 *    second call plans nothing twice; only a NEW device gets the packages that are auto-install by policy, but apps a technician ticked for that installer are always planned;
 *  - EXECUTION is the scheduler's ({@see advance()}): one app per device at a time, lowest `install_order` first; the device must be linked, online and announce
 *    `software:install`; the global limits (`software_max_installs`, per client) apply; a `required` app that failed stops the later ones (they are `skipped` with the reason),
 *    a best-effort app that failed does not;
 *  - MODE: an app a technician ticked is an explicit decision for that device and runs for real; an app that is auto-install by policy runs for real only when the limit
 *    `software_live_install` is 1 (otherwise a dry run, shown as `skipped` with the reason), exactly like every other scheduled install;
 *  - a package marked `two_person` still waits for a second person's approval (the approval is requested in the name of the technician who ticked it, or of the
 *    administrator who switched the package to auto-install); nobody approves their own request.
 *
 * Statuses: queued, installing, installed, failed, skipped (the internal `approval` is shown as `queued` with its reason).
 *
 * @api
 */
final class AutoInstallService
{
    public const SCOPES = ['off', 'all', 'clients', 'os'];
    public const STATUSES = ['queued', 'installing', 'installed', 'failed', 'skipped'];
    public const EXPIRE_AFTER_S = 1209600;
    public const RETRY_AFTER_S = 300;
    public const MAX_APPS_PER_INSTALLER = 20;
    private const SOURCE = 'autoinstall';

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly DeployService $service,
        private readonly PackageCatalog $catalog,
        private readonly DeployExpectations $expectations,
        private readonly PackageParams $params,
        private readonly DeployApprovalService $approvals,
        private readonly DeviceRepository $devices,
        private readonly RmmTenancyInterface $tenancy,
        private readonly RmmAuditInterface $audit,
    ) {
    }

    // ------------------------------------------------------------------ package settings

    /**
     * @return array{package_id:int,scope:string,os_filter:string,install_order:int,required:bool,clients:list<array{client_id:int,location_id:int}>}
     */
    public function settingsFor(int $packageId): array
    {
        $r = $this->sql->one('SELECT * FROM rmm_package_auto WHERE package_id = ?', [$packageId]);
        $clients = array_map(static fn (array $c): array => ['client_id' => (int) $c['client_id'], 'location_id' => (int) $c['location_id']],
            $this->sql->all('SELECT client_id, location_id FROM rmm_package_auto_clients WHERE package_id = ? ORDER BY client_id, location_id', [$packageId]));

        return ['package_id' => $packageId, 'scope' => $r === null ? 'off' : (string) $r['scope'], 'os_filter' => $r === null ? '' : (string) $r['os_filter'],
            'install_order' => $r === null ? 100 : (int) $r['install_order'], 'required' => $r !== null && (int) $r['required'] === 1, 'clients' => $clients];
    }

    /**
     * Set the auto-install policy of a package.
     *
     * @param array<string,mixed> $in {scope: off|all|clients|os, os?: windows|linux (scope os), clients?: list<int|{client_id,location_id?}> (scope clients), install_order?: 1..9999, required?: bool}
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function setAuto(int $packageId, array $in, int $userId): array
    {
        $pkg = $this->catalog->package($packageId);
        if ($pkg === null) {
            throw new \InvalidArgumentException('Package not found.');
        }
        $unknown = array_diff(array_keys($in), ['scope', 'os', 'clients', 'install_order', 'required']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown field: ' . mb_substr((string) array_values($unknown)[0], 0, 40) . '.');
        }
        $cur = $this->settingsFor($packageId);
        $scope = $in['scope'] ?? $cur['scope'];
        if (!is_string($scope) || !in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('scope is off, all (every new device), clients (chosen clients or departments) or os (one operating system).');
        }
        $os = '';
        if ($scope === 'os') {
            $os = $in['os'] ?? ($cur['os_filter'] !== '' ? $cur['os_filter'] : null);
            if (!is_string($os) || !in_array($os, DeployProtocol::OSES, true)) {
                throw new \InvalidArgumentException('os is windows or linux.');
            }
        }
        $order = $in['install_order'] ?? $cur['install_order'];
        if (!is_int($order) || $order < 1 || $order > 9999) {
            throw new \InvalidArgumentException('install_order is a whole number from 1 to 9999; lower numbers install first.');
        }
        $required = array_key_exists('required', $in) ? $in['required'] : $cur['required'];
        if (!is_bool($required)) {
            throw new \InvalidArgumentException('required is true or false.');
        }
        $clients = [];
        if ($scope === 'clients') {
            $raw = array_key_exists('clients', $in) ? $in['clients'] : $cur['clients'];
            if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > 200) {
                throw new \InvalidArgumentException('clients is a list of 1 to 200 client ids (or {client_id, location_id}).');
            }
            foreach ($raw as $c) {
                $cid = is_array($c) ? ($c['client_id'] ?? null) : $c;
                $lid = is_array($c) ? ($c['location_id'] ?? 0) : 0;
                if (!is_int($cid) || $cid <= 0 || !is_int($lid) || $lid < 0 || $this->tenancy->clientName($cid) === null || ($lid > 0 && !$this->tenancy->locationInClient($lid, $cid))) {
                    throw new \InvalidArgumentException('Every client must exist, and a location must belong to its client.');
                }
                $clients["$cid:$lid"] = ['client_id' => $cid, 'location_id' => $lid];
            }
        }
        if ($scope !== 'off' && !$pkg['enabled']) {
            throw new \InvalidArgumentException('Switch the package on before it installs on new devices.');
        }
        if ($scope !== 'off' && $pkg['versions'] < 1) {
            throw new \InvalidArgumentException('Add a version to the package first.');
        }
        $this->sql->transaction(function () use ($packageId, $scope, $os, $order, $required, $clients, $userId): bool {
            $this->sql->run('INSERT INTO rmm_package_auto (package_id, scope, os_filter, install_order, required, set_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE scope = VALUES(scope), os_filter = VALUES(os_filter), install_order = VALUES(install_order), required = VALUES(required), set_by = VALUES(set_by), updated_at = VALUES(updated_at)',
                [$packageId, $scope, $os, $order, $required ? 1 : 0, $userId, $this->sql->utcNow()]);
            $this->sql->run('DELETE FROM rmm_package_auto_clients WHERE package_id = ?', [$packageId]);
            foreach ($clients as $c) {
                $this->sql->run('INSERT INTO rmm_package_auto_clients (package_id, client_id, location_id) VALUES (?, ?, ?)', [$packageId, $c['client_id'], $c['location_id']]);
            }

            return true;
        });

        return $this->settingsFor($packageId);
    }

    /**
     * Every package with its auto-install policy, the parameter names it declares and where each is set (never a value).
     *
     * @return list<array<string,mixed>>
     */
    public function packages(): array
    {
        $out = [];
        foreach ($this->catalog->packages() as $p) {
            $id = (int) $p['package_id'];
            $out[] = ['package_id' => $id, 'name' => $p['name'], 'publisher' => $p['publisher'], 'enabled' => $p['enabled'], 'two_person' => $p['two_person'], 'versions' => $p['versions'],
                'auto' => $this->settingsFor($id), 'params' => ['declared' => $this->params->declared($id), 'set' => $this->params->status($id)]];
        }

        return $out;
    }

    // ------------------------------------------------------------------ what applies

    /**
     * Whether a package's auto-install policy covers a (new) device of this client, location and operating system.
     *
     * @param array{scope:string,os_filter:string,clients:list<array{client_id:int,location_id:int}>} $s
     */
    private static function covers(array $s, int $clientId, int $locationId, string $os): bool
    {
        return match ($s['scope']) {
            'all' => true,
            'os' => $s['os_filter'] === $os,
            'clients' => array_filter($s['clients'], static fn (array $c): bool => $c['client_id'] === $clientId && ($c['location_id'] === 0 || $c['location_id'] === $locationId)) !== [],
            default => false,
        };
    }

    /**
     * The installer-time helper: every enabled package that has a version for the operating system, with whether it is auto-install for a new device of this client
     * and location (pre-ticked in the installer page), its order, whether it is required, and which vendor parameters still have no value for it. Names only.
     *
     * @param string $os windows or linux, or '' for the installer's own OS (Windows)
     * @return list<array<string,mixed>>
     */
    public function selectable(int $clientId, int $locationId, string $os = 'windows'): array
    {
        $os = $os === 'linux' ? 'linux' : 'windows';
        $out = [];
        foreach ($this->catalog->packages() as $p) {
            if (!$p['enabled']) {
                continue;
            }
            $v = $this->newestVersion((int) $p['package_id'], $os);
            if ($v === null) {
                continue;
            }
            $s = $this->settingsFor((int) $p['package_id']);
            $names = $os === 'windows' && $v['fmt'] === 'exe' ? ArgsTemplate::names((string) $v['silent_args']) : [];
            $out[] = ['package_id' => (int) $p['package_id'], 'name' => $p['name'], 'publisher' => $p['publisher'], 'version' => $v['version'], 'fmt' => $v['fmt'],
                'auto' => self::covers($s, $clientId, $locationId, $os), 'install_order' => $s['install_order'], 'required' => $s['required'], 'two_person' => $p['two_person'],
                'missing_params' => $this->params->missing((int) $p['package_id'], $clientId, $locationId, $names)];
        }
        usort($out, static fn (array $a, array $b): int => [$a['install_order'], $a['name']] <=> [$b['install_order'], $b['name']]);

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function newestVersion(int $packageId, string $os): ?array
    {
        $id = $this->sql->val('SELECT version_id FROM rmm_package_versions WHERE package_id = ? AND os = ? AND retired = 0 ORDER BY version_id DESC LIMIT 1', [$packageId, $os]);

        return $id === null ? null : $this->catalog->version((int) $id);
    }

    // ------------------------------------------------------------------ installer-time selection

    /**
     * Record the apps a technician ticked for an installer (enrollment token). Refuses unknown or switched-off packages, packages without a version for Windows, and
     * packages whose vendor parameters have no value yet for that client, so a mistake shows at issue time and not on the new PC. The audit row says who ticked what.
     *
     * @param list<int> $packageIds
     * @return string|null why not, or null
     */
    public function recordSelection(int $tokenId, array $packageIds, int $clientId, int $locationId, int $userId, string $actorName): ?string
    {
        $packageIds = array_values(array_unique($packageIds));
        if ($packageIds === []) {
            return null;
        }
        if (!$this->settings->featureOn(DeployProtocol::FEATURE)) {
            return 'Software deployment is switched off, so no app would be installed. An administrator can turn it on in the RMM settings.';
        }
        if (count($packageIds) > self::MAX_APPS_PER_INSTALLER) {
            return 'At most ' . self::MAX_APPS_PER_INSTALLER . ' apps per installer.';
        }
        $names = [];
        foreach ($packageIds as $id) {
            $p = $this->catalog->package($id);
            if ($p === null || !$p['enabled']) {
                return 'One of the chosen apps does not exist or is switched off.';
            }
            $v = $this->newestVersion($id, 'windows') ?? $this->newestVersion($id, 'linux');
            if ($v === null) {
                return $p['name'] . ' has no version in the catalog that is not retired.';
            }
            $need = $v['fmt'] === 'exe' ? ArgsTemplate::names((string) $v['silent_args']) : [];
            $missing = $this->params->missing($id, $clientId, $locationId, $need);
            if ($missing !== []) {
                return $p['name'] . ' needs vendor parameters that are not set for this ' . 'client: ' . implode(', ', $missing) . '. An administrator sets them in the software catalog.';
            }
            $names[] = $p['name'];
        }
        $now = $this->sql->utcNow();
        foreach ($packageIds as $id) {
            $this->sql->run('INSERT IGNORE INTO rmm_installer_apps (token_id, package_id, selected_by, selected_at) VALUES (?, ?, ?, ?)', [$tokenId, $id, $userId, $now]);
        }
        $this->audit->record('Installer Apps Chosen', "$actorName ticked " . implode(', ', array_map(static fn (string $n): string => '"' . mb_substr($n, 0, 60) . '"', $names))
            . " to install with the agent from enrollment token #$tokenId (explicit approval for the devices this installer enrolls)", $clientId, 0);

        return null;
    }

    // ------------------------------------------------------------------ planning (enrollment)

    /**
     * Plan the apps of a device that has just enrolled. Safe to call again: it never plans an app twice.
     *
     * @param array<string,mixed> $dev the device row
     * @param string $event enrolled (a new device), re-enrolled or reinstalled: only a new device gets the auto-install policies; ticked apps are always planned
     * @return int apps newly planned
     */
    public function planDevice(array $dev, ?int $tokenId, string $event): int
    {
        if (!$this->settings->featureOn(DeployProtocol::FEATURE)) {
            return 0;
        }
        $deviceId = (int) $dev['device_id'];
        $client = (int) $dev['client_id'];
        $loc = (int) ($dev['location_id'] ?? 0);
        $os = (string) ($dev['os'] ?? '');
        $plan = [];   // package_id => [source, selected_by, order, required]
        if ($event === 'enrolled') {
            foreach ($this->sql->all("SELECT a.* FROM rmm_package_auto a JOIN rmm_packages p ON p.package_id = a.package_id WHERE a.scope <> 'off' AND p.enabled = 1 ORDER BY a.install_order, a.package_id") as $a) {
                $s = $this->settingsFor((int) $a['package_id']);
                if (self::covers($s, $client, $loc, $os)) {
                    $plan[(int) $a['package_id']] = ['auto', (int) $a['set_by'], (int) $a['install_order'], (int) $a['required']];
                }
            }
        }
        if ($tokenId !== null) {
            foreach ($this->sql->all('SELECT i.package_id, i.selected_by, COALESCE(a.install_order, 100) AS install_order, COALESCE(a.required, 0) AS required FROM rmm_installer_apps i
                JOIN rmm_packages p ON p.package_id = i.package_id AND p.enabled = 1 LEFT JOIN rmm_package_auto a ON a.package_id = i.package_id WHERE i.token_id = ?', [$tokenId]) as $c) {
                $plan[(int) $c['package_id']] = ['chosen', (int) $c['selected_by'], (int) $c['install_order'], (int) $c['required']];   // a ticked app is explicit: it wins over the policy
            }
        }
        $n = 0;
        $now = $this->sql->utcNow();
        $labels = [];
        foreach ($plan as $pid => [$source, $by, $order, $required]) {
            $n += $this->sql->run('INSERT IGNORE INTO rmm_device_apps (device_id, package_id, client_id, source, token_id, selected_by, install_order, required, status, reason, planned_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'queued\', \'waiting for the device to be ready\', ?, ?)', [$deviceId, $pid, $client, $source, $source === 'chosen' ? $tokenId : null, $by > 0 ? $by : null, $order, $required, $now, $now]);
            if ($source === 'chosen') {
                // A tick on a later installer promotes an app that was only planned by policy (and was not run yet) to an explicit choice.
                $this->sql->run("UPDATE rmm_device_apps SET source = 'chosen', token_id = ?, selected_by = ?, updated_at = ? WHERE device_id = ? AND package_id = ? AND source = 'auto' AND status IN ('queued','skipped')", [$tokenId, $by > 0 ? $by : null, $now, $deviceId, $pid]);
            }
            $labels[] = "#$pid ($source)";
        }
        if ($n > 0) {
            $this->audit->record('Auto-install Planned', "Device $deviceId ({$dev['hostname']}) enrolled ($event): planned " . implode(', ', $labels), $client, (int) ($dev['asset_id'] ?? 0));
        }

        return $n;
    }

    // ------------------------------------------------------------------ execution (the scheduler)

    /**
     * One scheduler step: queue the next app of each device that is ready. Bounded by `$batch` devices.
     *
     * @return array{queued:int,dry:int,approvals:int,skipped:int,deferred:int}
     */
    public function advance(int $batch): array
    {
        $out = ['queued' => 0, 'dry' => 0, 'approvals' => 0, 'skipped' => 0, 'deferred' => 0];
        if (!$this->settings->featureOn(DeployProtocol::FEATURE) || !$this->settings->featureOn('jobs')) {
            return $out;
        }
        $now = $this->sql->time();
        $out['skipped'] += $this->sql->run("UPDATE rmm_device_apps SET status = 'skipped', reason = 'the device was not ready to install within 14 days', updated_at = ? WHERE status IN ('queued','approval') AND planned_at < ?",
            [$this->sql->utcNow(), $this->sql->utcAt(-self::EXPIRE_AFTER_S)]);
        $limits = $this->settings->limits();
        $live = (int) $limits['software_live_install'] === 1;
        $offline = (int) $this->settings->get()['offline_after_s'];
        $perClient = [];
        $total = 0;
        foreach ($this->sql->all("SELECT j.client_id, COUNT(*) AS c FROM rmm_package_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id
            WHERE p.type = 'software_install' AND p.mode = 'run' AND j.state IN ('queued','running') GROUP BY j.client_id") as $r) {
            $perClient[(int) $r['client_id']] = (int) $r['c'];
            $total += (int) $r['c'];
        }
        $rows = $this->sql->all("SELECT d.* FROM endpoint_agent_devices d
            JOIN rmm_device_state st ON st.device_id = d.device_id AND st.capabilities_json LIKE '%\"software:install\"%'
            WHERE d.link_state = 'linked' AND d.revoked_at IS NULL AND d.retired_at IS NULL AND d.last_checkin_at > ?
              AND EXISTS (SELECT 1 FROM rmm_device_apps a WHERE a.device_id = d.device_id AND a.status = 'queued')
              AND NOT EXISTS (SELECT 1 FROM rmm_device_apps a WHERE a.device_id = d.device_id AND a.status IN ('installing','approval'))
            ORDER BY d.device_id LIMIT " . max(1, $batch), [$this->sql->utcAt(-$offline)]);
        foreach ($rows as $dev) {
            $r = $this->advanceDevice($dev, $live, $limits, $perClient, $total);
            foreach ($r as $k => $v) {
                $out[$k] += $v;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $dev
     * @param array<string,int> $limits
     * @param array<int,int> $perClient
     * @return array<string,int>
     */
    private function advanceDevice(array $dev, bool $live, array $limits, array &$perClient, int &$total): array
    {
        $out = ['queued' => 0, 'dry' => 0, 'approvals' => 0, 'skipped' => 0, 'deferred' => 0];
        $deviceId = (int) $dev['device_id'];
        if ($this->service->pendingJob($deviceId, DeployProtocol::JOB_INSTALL) !== null) {
            return $out;   // one install at a time per device
        }
        $now = $this->sql->utcNow();
        // A required app that failed (or was refused) stops the ones after it.
        $blocked = $this->sql->one("SELECT a.install_order, p.name FROM rmm_device_apps a JOIN rmm_packages p ON p.package_id = a.package_id
            WHERE a.device_id = ? AND a.required = 1 AND a.status = 'failed' ORDER BY a.install_order LIMIT 1", [$deviceId]);
        if ($blocked !== null) {
            $n = $this->sql->run("UPDATE rmm_device_apps SET status = 'skipped', reason = ?, updated_at = ? WHERE device_id = ? AND status = 'queued' AND install_order >= ?",
                ['not started: the required app "' . mb_substr((string) $blocked['name'], 0, 60) . '" failed', $now, $deviceId, (int) $blocked['install_order']]);
            if ($n > 0) {
                return ['skipped' => $n] + $out;
            }
        }
        $next = $this->sql->all("SELECT a.*, p.name, p.enabled, p.two_person FROM rmm_device_apps a JOIN rmm_packages p ON p.package_id = a.package_id
            WHERE a.device_id = ? AND a.status = 'queued' AND (a.attempts = 0 OR a.updated_at <= ?) ORDER BY a.install_order, a.package_id LIMIT 1", [$deviceId, $this->sql->utcAt(-self::RETRY_AFTER_S)]);
        if ($next === []) {
            return $out;
        }
        $a = $next[0];
        $pid = (int) $a['package_id'];
        $skip = function (string $why) use ($deviceId, $pid, &$out): array {
            $this->setStatus($deviceId, $pid, 'skipped', $why);
            ++$out['skipped'];

            return $out;
        };
        if ((int) $a['enabled'] !== 1) {
            return $skip('the package is switched off');
        }
        $v = $this->expectations->versionFor($dev, $pid, null, 'install');
        if ($v === null) {
            return $skip('the catalog has no version of this app for this device');
        }
        $explicit = (string) $a['source'] === 'chosen';
        $mode = $explicit || $live ? 'run' : 'dry_run';
        $client = (int) $dev['client_id'];
        if ($mode === 'run' && ($total >= (int) $limits['software_max_installs'] || ($perClient[$client] ?? 0) >= (int) $limits['software_max_installs_per_client'])) {
            ++$out['deferred'];

            return $out;
        }
        $by = $a['selected_by'] === null ? 0 : (int) $a['selected_by'];
        if ($mode === 'run' && (int) $a['two_person'] === 1) {
            if ($by <= 0) {
                return $skip('this package needs a second person to approve it and nobody is on record as having asked for it');
            }
            $ap = $this->approvals->requestRun($dev, $v, 'install', $by, DeployProtocol::MAX_MINUTES_DEFAULT);
            $this->setStatus($deviceId, $pid, 'approval', 'waiting for a second person to approve (approval #' . (int) $ap['approval_id'] . ')', (int) $v['version_id']);
            ++$out['approvals'];

            return $out;
        }
        $q = $this->service->queueInstall($dev, $v, 'install', $mode, ['source' => self::SOURCE, 'user_id' => $by > 0 ? $by : null, 'idem' => true]);
        if (!$q['ok']) {
            $this->setStatus($deviceId, $pid, 'failed', mb_substr((string) ($q['error'] ?? 'The install could not be queued.'), 0, 200), (int) $v['version_id']);

            return $out;
        }
        if (isset($q['skipped']) || !isset($q['job_id'])) {
            return $out;   // already queued or a duplicate: try again next pass
        }
        $this->sql->run("UPDATE rmm_device_apps SET status = 'installing', reason = ?, version_id = ?, job_id = ?, updated_at = ? WHERE device_id = ? AND package_id = ?",
            [$mode === 'run' ? 'installing' . ($explicit ? ' (chosen by a technician)' : '') : 'dry run only: live installs are switched off', $v['version_id'], $q['job_id'], $now, $deviceId, $pid]);
        if ($mode === 'run') {
            ++$out['queued'];
            ++$total;
            $perClient[$client] = ($perClient[$client] ?? 0) + 1;
        } else {
            ++$out['dry'];
        }

        return $out;
    }

    private function setStatus(int $deviceId, int $packageId, string $status, string $reason, ?int $versionId = null): void
    {
        $this->sql->run('UPDATE rmm_device_apps SET status = ?, reason = ?, version_id = COALESCE(?, version_id), updated_at = ? WHERE device_id = ? AND package_id = ?',
            [$status, mb_substr($reason, 0, 200), $versionId, $this->sql->utcNow(), $deviceId, $packageId]);
    }

    // ------------------------------------------------------------------ results

    /**
     * A software_install job of this device and package finished (called by {@see DeployService::applyJob()}). Only a row that is waiting on it is changed.
     *
     * @param array{ok:bool,result:string,code:string,detail:string,version:string,reboot_required:bool} $parsed
     */
    public function onInstallResult(int $deviceId, int $packageId, array $parsed, string $mode, bool $lost): void
    {
        $row = $this->sql->one("SELECT * FROM rmm_device_apps WHERE device_id = ? AND package_id = ? AND status IN ('installing','approval','queued')", [$deviceId, $packageId]);
        if ($row === null) {
            return;
        }
        $res = $parsed['result'];
        $attempts = (int) $row['attempts'];
        if ($lost) {
            $this->sql->run("UPDATE rmm_device_apps SET status = 'queued', reason = 'the job did not run; it will be queued again', updated_at = ? WHERE device_id = ? AND package_id = ?", [$this->sql->utcNow(), $deviceId, $packageId]);

            return;
        }
        if (in_array($res, DeployProtocol::DRY_RESULTS, true)) {
            $this->setStatus($deviceId, $packageId, 'skipped', 'dry run only: it would install. Live installs are switched off (software_live_install).');
        } elseif (in_array($res, DeployProtocol::OK_RESULTS, true)) {
            $this->setStatus($deviceId, $packageId, 'installed', $res === 'already_installed' ? 'already present' : ($res === 'needs_reboot' ? 'installed; the device needs a restart' : 'installed' . ($parsed['version'] !== '' ? ' (' . mb_substr($parsed['version'], 0, 40) . ')' : '')));
        } else {
            $attempts++;
            $why = $res . ($parsed['code'] !== '' ? ' (' . $parsed['code'] . ')' : '') . ($parsed['detail'] !== '' ? ': ' . $parsed['detail'] : '');
            $retry = in_array($res, ['locked', 'download_failed'], true) && $attempts < DeployProtocol::MAX_ATTEMPTS;
            $this->sql->run('UPDATE rmm_device_apps SET status = ?, reason = ?, attempts = ?, updated_at = ? WHERE device_id = ? AND package_id = ?',
                [$retry ? 'queued' : 'failed', mb_substr(($retry ? 'will retry: ' : '') . $why, 0, 200), $attempts, $this->sql->utcNow(), $deviceId, $packageId]);
        }
    }

    // ------------------------------------------------------------------ read model

    /**
     * What the device was told to install with the agent and where each app stands.
     *
     * @return list<array<string,mixed>>
     */
    public function deviceApps(int $deviceId): array
    {
        return array_map(static fn (array $r): array => ['package_id' => (int) $r['package_id'], 'name' => (string) $r['name'], 'status' => (string) $r['status'] === 'approval' ? 'queued' : (string) $r['status'],
            'reason' => (string) $r['reason'], 'source' => (string) $r['source'], 'selected_by' => $r['selected_by'] === null ? null : (int) $r['selected_by'], 'install_order' => (int) $r['install_order'],
            'required' => (int) $r['required'] === 1, 'version_id' => $r['version_id'] === null ? null : (int) $r['version_id'], 'attempts' => (int) $r['attempts'],
            'planned_at' => Sql::iso((string) $r['planned_at']), 'updated_at' => Sql::iso((string) $r['updated_at'])],
            $this->sql->all('SELECT a.*, p.name FROM rmm_device_apps a JOIN rmm_packages p ON p.package_id = a.package_id WHERE a.device_id = ? ORDER BY a.install_order, a.package_id', [$deviceId]));
    }

    /** Housekeeping: rows of deleted devices, and finished rows after a long time. */
    public function prune(): int
    {
        return $this->sql->run('DELETE a FROM rmm_device_apps a WHERE NOT EXISTS (SELECT 1 FROM endpoint_agent_devices d WHERE d.device_id = a.device_id)')
            + $this->sql->run("DELETE FROM rmm_device_apps WHERE status IN ('installed','skipped','failed') AND updated_at < ?", [$this->sql->utcAt(-180 * 86400)])
            + $this->sql->run('DELETE i FROM rmm_installer_apps i WHERE NOT EXISTS (SELECT 1 FROM endpoint_agent_enrollment_tokens t WHERE t.token_id = i.token_id)');
    }
}
