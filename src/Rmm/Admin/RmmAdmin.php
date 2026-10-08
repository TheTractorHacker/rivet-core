<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Admin;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Binaries\BinaryStore;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Rmm\Technician\ActionResult;
use RivetCore\Rmm\Installer\InstallerService;
use RivetCore\Rmm\Mesh\MeshService;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Update\UpdateService;

/**
 * The validated administration operations the edition's settings page calls: module settings (switch, intervals, limits, check
 * schedule, CA certificate, sub-switches and capacity limits), the MeshCentral settings, the signing key, agent binaries, releases and
 * rings, and installers. The edition renders the form and the flash message and keeps its CSRF and session rules; every rule that decides
 * whether a value is acceptable lives here, so no edition writes SQL against endpoint_agent_*.
 *
 * Every method authorizes the principal itself (ability `rmm.admin`, `rmm.binary.publish` or `rmm.token.manage`), works while the module
 * is switched off (turning it on is one of the operations) and returns an {@see \RivetCore\Rmm\Technician\ActionResult}. Nothing is
 * written when validation fails. Whenever a mirrored setting changes, the zero-database state file follows (RmmSettings does that).
 *
 * @api
 */
final class RmmAdmin
{
    /** Sub-switch presets offered when the master is switched on for the first time (design 12.1). */
    public const FEATURE_PRESETS = [
        'light' => ['monitoring' => true, 'updates' => true],
        'standard' => ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'remote' => true, 'updates' => true],
    ];

    public const KEY_ROTATED_WARNING = 'A new signing key was generated. Agents trust the key they received at enrollment, so every device must re-enroll (Rotate credential) before it accepts jobs again.';

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly SecretBoxInterface $box,
        private readonly RmmAuthorizer $authz,
        private readonly BinaryStore $binaries,
        private readonly UpdateService $updates,
        private readonly MeshService $mesh,
        private readonly InstallerService $installers,
        private readonly DeviceRepository $devices,
        private readonly RmmAuditInterface $audit,
    ) {
    }

    // ------------------------------------------------------------------ settings

    /**
     * Save the settings form. Only the keys present are touched; whole numbers are clamped to their range, anything else invalid refuses
     * the whole save. `ca_pem` is normalised here (pasted PEM text; empty clears it). `enabled` switches the module (the first switch-on
     * mints the signing key and the integration row). `features_json` / `limits_json` take JSON text or arrays.
     *
     * @param array<string,mixed> $in column => value, see {@see RmmSettings::update()}
     */
    public function saveSettings(RmmPrincipal $by, array $in): ActionResult
    {
        if (($d = $this->deny($by, RmmAbility::ADMIN)) !== null) {
            return $d;
        }
        if (array_key_exists('ca_pem', $in)) {
            [$pem, $err] = InstallerService::normalizeCa(is_scalar($in['ca_pem']) ? (string) $in['ca_pem'] : '');
            if ($err !== null) {
                return ActionResult::fail(422, 'invalid', $err, ['errors' => [$err]]);
            }
            $in['ca_pem'] = $pem;
        }
        $errors = $this->settings->update($in);
        if ($errors !== []) {
            return ActionResult::fail(422, 'invalid', implode(' ', $errors), ['errors' => $errors]);
        }
        $on = $this->settings->enabled();
        $this->audit->record('Settings', "{$by->userName} edited the endpoint agent settings (" . ($on ? 'on' : 'off') . ')', 0, 0);

        return ActionResult::ok('Endpoint agent settings saved.', 200, 'saved', ['enabled' => $on]);
    }

    /** Switch the module on (mints the signing key and the integration row the first time). */
    public function enable(RmmPrincipal $by): ActionResult
    {
        if (($d = $this->deny($by, RmmAbility::ADMIN)) !== null) {
            return $d;
        }
        $this->settings->enable();
        $this->audit->record('Settings', "{$by->userName} switched the endpoint agent on", 0, 0);

        return ActionResult::ok('Endpoint agent switched on.', 200, 'enabled');
    }

    /** Switch the module off. Nothing is deleted; devices keep their credentials and come back by themselves when it is switched on again. */
    public function disable(RmmPrincipal $by): ActionResult
    {
        if (($d = $this->deny($by, RmmAbility::ADMIN)) !== null) {
            return $d;
        }
        $this->settings->disable();
        $this->audit->record('Settings', "{$by->userName} switched the endpoint agent off", 0, 0);

        return ActionResult::ok('Endpoint agent switched off. Nothing was deleted.', 200, 'disabled');
    }

    /**
     * Set the sub-switches from a preset (`light`, `standard`) or back to today's behaviour (`legacy`: monitoring, metrics, jobs and
     * updates on, remote following the MeshCentral switch).
     */
    public function applyFeaturePreset(RmmPrincipal $by, string $preset): ActionResult
    {
        if (($d = $this->deny($by, RmmAbility::ADMIN)) !== null) {
            return $d;
        }
        if ($preset !== 'legacy' && !isset(self::FEATURE_PRESETS[$preset])) {
            return ActionResult::fail(422, 'invalid', 'Unknown preset. Choose light, standard or legacy.');
        }
        $errors = $this->settings->update(['features_json' => $preset === 'legacy' ? null : self::FEATURE_PRESETS[$preset]]);
        if ($errors !== []) {
            return ActionResult::fail(422, 'invalid', implode(' ', $errors), ['errors' => $errors]);
        }
        $this->audit->record('Settings', "{$by->userName} applied the '$preset' feature preset to the endpoint agent", 0, 0);

        return ActionResult::ok('Features updated.', 200, 'saved', ['features' => $this->settings->features()]);
    }

    // ------------------------------------------------------------------ MeshCentral settings

    /**
     * Save the MeshCentral settings (and optionally test the connection). `mesh_login_key` is write-only: empty keeps the stored key.
     *
     * @param array<string,mixed> $in mesh_enabled, mesh_url, mesh_domain, mesh_account_template, mesh_policy, mesh_token_ttl_s, mesh_login_key
     * @return ActionResult data: `probe` (null when MeshCentral answered, otherwise the error text) when $test is set and a URL is saved
     */
    public function saveMesh(RmmPrincipal $by, array $in, bool $test = false): ActionResult
    {
        if (($d = $this->deny($by, RmmAbility::ADMIN)) !== null) {
            return $d;
        }
        $url = trim(is_scalar($in['mesh_url'] ?? null) ? (string) $in['mesh_url'] : '');
        $norm = $url === '' ? '' : $this->mesh->normalizeUrl($url);
        if ($norm === null) {
            return ActionResult::fail(422, 'invalid', 'The MeshCentral address must be a plain https:// address without credentials or a query.');
        }
        $domain = trim(is_scalar($in['mesh_domain'] ?? null) ? (string) $in['mesh_domain'] : '');
        $account = trim(is_scalar($in['mesh_account_template'] ?? null) ? (string) $in['mesh_account_template'] : RmmProtocol::MESH_DEFAULT_ACCOUNT_TEMPLATE);
        if (preg_match('/^[A-Za-z0-9._{}-]{1,100}$/', $account) !== 1 || ($domain !== '' && preg_match('/^[A-Za-z0-9._-]{1,100}$/', $domain) !== 1)) {
            return ActionResult::fail(422, 'invalid', 'The MeshCentral domain and account name may only contain letters, digits and . _ -');
        }
        $policy = $in['mesh_policy'] ?? 'unattended';
        $ttl = $in['mesh_token_ttl_s'] ?? RmmSettings::MESH_TOKEN_TTL_DEFAULT_S;
        $vals = [
            'mesh_enabled' => !empty($in['mesh_enabled']) ? 1 : 0,
            'mesh_url' => $norm,
            'mesh_domain' => $domain,
            'mesh_account_template' => $account,
            'mesh_policy' => in_array($policy, ['unattended', 'attended', 'both'], true) ? $policy : 'unattended',
            'mesh_token_ttl_s' => is_numeric($ttl) ? max(RmmSettings::MESH_TOKEN_TTL_MIN_S, min(RmmSettings::MESH_TOKEN_TTL_MAX_S, (int) $ttl)) : RmmSettings::MESH_TOKEN_TTL_DEFAULT_S,
        ];
        $key = trim(is_scalar($in['mesh_login_key'] ?? null) ? (string) $in['mesh_login_key'] : '');
        if ($key !== '') {
            if (preg_match('/^[0-9a-fA-F]{64,}$/', $key) !== 1) {
                return ActionResult::fail(422, 'invalid', 'The login token key is the long hex string printed by "meshcentral --loginTokenKey".');
            }
            $vals['mesh_login_key_enc'] = $this->box->encrypt(strtolower($key));
        }
        $this->settings->set($vals);
        $this->audit->record('Settings', "{$by->userName} edited the MeshCentral settings for the endpoint agent" . ($key !== '' ? ' (login key replaced)' : ''), 0, 0);
        if ($test && $norm !== '') {
            $err = $this->mesh->probe($norm);

            return $err === null ? ActionResult::ok('MeshCentral answered.', 200, 'ok', ['probe' => null]) : ActionResult::fail(503, 'mesh_unavailable', $err, ['probe' => $err, 'saved' => true]);
        }

        return ActionResult::ok('MeshCentral settings saved.', 200, 'saved', ['probe' => null]);
    }

    // ------------------------------------------------------------------ signing key

    /**
     * Mint a new instance signing key. Agents trust the key they received at enrollment, so EVERY enrolled device must re-enroll before
     * it accepts jobs or updates again: the result carries that warning and the number of live devices affected.
     */
    public function rotateSigningKey(RmmPrincipal $by): ActionResult
    {
        if (($d = $this->deny($by, RmmAbility::ADMIN)) !== null) {
            return $d;
        }
        $kid = $this->settings->generateSigningKey();
        $affected = $this->devices->liveCount();
        $this->audit->record('Settings', "{$by->userName} rotated the endpoint agent signing key (new key id $kid)", 0, 0);

        return ActionResult::ok(self::KEY_ROTATED_WARNING, 200, 'rotated', ['key_id' => $kid, 'devices_to_reenroll' => $affected, 'warning' => true]);
    }

    /**
     * What an administrator may see about the signing key: ids and dates, never key material.
     *
     * @return array{set:bool,key_id:string,public_key:string,created_at:?string,algorithm:string,devices_bound:int}
     */
    public function keyFacts(): array
    {
        $c = $this->settings->get(true);
        $set = (string) $c['signing_public_key'] !== '' && !empty($c['signing_private_key_enc']);

        return ['set' => $set, 'key_id' => (string) $c['signing_key_id'], 'public_key' => (string) $c['signing_public_key'],
            'created_at' => Sql::iso($c['signing_key_created_at'] === null ? null : (string) $c['signing_key_created_at']), 'algorithm' => 'ed25519',
            'devices_bound' => $this->devices->liveCount()];
    }

    // ------------------------------------------------------------------ binaries, releases and rings

    /**
     * Publish an uploaded agent executable (the edition has already verified it is an uploaded file).
     *
     * @param array{activate?:bool,release_ring?:?string,rollout_pct?:int,notes?:string} $opts
     * @return ActionResult data: `binary` row on success
     */
    public function uploadBinary(RmmPrincipal $by, string $tmpPath, string $version, string $arch, array $opts = []): ActionResult
    {
        if (($d = $this->deny($by, RmmAbility::BINARY_PUBLISH)) !== null) {
            return $d;
        }
        $ring = (string) ($opts['release_ring'] ?? '');
        $opts['release_ring'] = in_array($ring, RmmProtocol::RINGS, true) ? $ring : null;
        try {
            $res = $this->binaries->publish($tmpPath, trim($version), $arch, $by->userId, $opts);
        } catch (\RuntimeException $e) {
            return ActionResult::fail(500, 'internal', $e->getMessage());
        }
        if (!$res['ok'] || !isset($res['binary'])) {
            return ActionResult::fail(422, 'invalid', (string) ($res['error'] ?? 'The binary could not be published.'));
        }
        $b = $res['binary'];
        $this->audit->record('Binary Uploaded', "{$by->userName} uploaded agent binary {$b['version']} ({$b['arch']}, sha256 {$b['sha256']}, {$b['size_bytes']} bytes)"
            . ((int) $b['is_current'] === 1 ? ' and made it current' : '') . ($opts['release_ring'] !== null ? " and offered it to the {$opts['release_ring']} ring" : ''), 0, 0);

        return ActionResult::ok('Agent binary ' . $b['version'] . ' (' . $b['arch'] . ') stored. SHA-256 ' . $b['sha256'] . '.', 200, 'stored', ['binary' => $b, 'created' => (bool) ($res['created'] ?? false)]);
    }

    /**
     * Change one stored binary.
     *
     * @param string $action make_current | deactivate | activate | offer_update (needs $ring and $pct)
     */
    public function binaryAction(RmmPrincipal $by, int $binaryId, string $action, string $ring = 'pilot', int $pct = 10): ActionResult
    {
        if (($d = $this->deny($by, RmmAbility::BINARY_PUBLISH)) !== null) {
            return $d;
        }
        $b = $this->sql->one('SELECT * FROM endpoint_agent_binaries WHERE binary_id = ?', [$binaryId]);
        if ($b === null) {
            return ActionResult::fail(404, 'not_found', 'Nothing changed.');
        }
        if ($action === 'make_current' && (int) $b['active'] === 1) {
            $done = $this->binaries->setCurrent($binaryId);
            $msg = 'This binary is now the one new installers are built from.';
        } elseif ($action === 'deactivate') {
            $done = $this->binaries->deactivate($binaryId);
            $msg = 'Binary deactivated: it is no longer used for installers or offered as an update. The file is kept.';
        } elseif ($action === 'activate') {
            $done = $this->binaries->activate($binaryId);
            $msg = 'Binary reactivated. Offer it as an update again if you want devices to receive it.';
        } elseif ($action === 'offer_update') {
            $e = $this->binaries->publishRelease($binaryId, $ring, $pct, '', $by->userId);
            $done = $e === null;
            $msg = $e ?? 'Offered to enrolled agents. Adjust the rollout under Agent updates and rings.';
        } else {
            return ActionResult::fail(422, 'invalid', 'Nothing changed.');
        }
        if (!$done) {
            return ActionResult::fail($action === 'offer_update' ? 422 : 409, $action === 'offer_update' ? 'invalid' : 'conflict', $msg);
        }
        $this->audit->record('Binary Changed', "{$by->userName} ran '$action' on agent binary #$binaryId ({$b['version']} {$b['arch']})", 0, 0);

        return ActionResult::ok($msg);
    }

    /** Offer a package hosted elsewhere (a legacy manual release). */
    public function addExternalRelease(RmmPrincipal $by, string $version, string $url, string $sha256, string $minVersion, string $ring, int $pct, string $notes): ActionResult
    {
        if (($d = $this->deny($by, RmmAbility::BINARY_PUBLISH)) !== null) {
            return $d;
        }
        $err = $this->updates->addRelease(trim($version), trim($url), trim($sha256), trim($minVersion) !== '' ? trim($minVersion) : '0.0.0', $ring, $pct, trim($notes), $by->userId);

        return $err === null ? ActionResult::ok('Release saved.') : ActionResult::fail(422, 'invalid', $err);
    }

    /** Change the rollout percentage and the active flag of a release (0 pauses a rollout, inactive withdraws it). */
    public function updateRelease(RmmPrincipal $by, int $releaseId, int $pct, bool $active): ActionResult
    {
        if (($d = $this->deny($by, RmmAbility::BINARY_PUBLISH)) !== null) {
            return $d;
        }
        if (!$this->binaries->updateRelease($releaseId, $pct, $active)) {
            return ActionResult::fail(404, 'not_found', 'No such release.');
        }
        $this->audit->record('Release Updated', "{$by->userName} changed rollout of agent release #$releaseId", 0, 0);

        return ActionResult::ok('Release updated.');
    }

    // ------------------------------------------------------------------ installers

    /**
     * The administrator's "download installer": an audited enrollment token and the stamped installer as a download.
     *
     * @return ActionResult data: `download` (an {@see RmmResponse}) on success; on a refusal the message says why and no token is left behind
     */
    public function downloadInstaller(RmmPrincipal $by, int $clientId, int $locationId, string $ring, int $ttlHours, int $maxUses, string $label, string $arch): ActionResult
    {
        if (($d = $this->deny($by, RmmAbility::TOKEN_MANAGE, $clientId)) !== null) {
            return $d;
        }
        $out = $this->installers->issueDownload($clientId, $locationId, $ring, $ttlHours, $maxUses, trim($label), $arch, $by->userId, $by->userName);
        if (is_string($out)) {
            return ActionResult::fail(422, 'invalid', $out);
        }

        return ActionResult::ok('Installer ready.', 200, 'ok', ['download' => $out]);
    }

    /**
     * "Show deployment commands": an audited enrollment token plus ready-to-paste commands (the token is shown once).
     *
     * @return ActionResult data: `token` (row), `token_plain`, `commands` (see {@see InstallerService::deploymentCommands()})
     */
    public function deploymentCommands(RmmPrincipal $by, int $clientId, int $locationId, string $ring, int $ttlHours, int $maxUses, string $label, string $arch): ActionResult
    {
        if (($d = $this->deny($by, RmmAbility::TOKEN_MANAGE, $clientId)) !== null) {
            return $d;
        }
        $r = $this->installers->issue($clientId, $locationId, $ring, $ttlHours, $maxUses, trim($label), $arch, $by->userId, $by->userName);
        if (!$r['ok'] || !isset($r['token'], $r['token_plain'], $r['department'])) {
            return ActionResult::fail(422, 'invalid', (string) ($r['error'] ?? 'The installer could not be created.'));
        }
        $commands = $this->installers->deploymentCommands(['token' => $r['token'], 'token_plain' => $r['token_plain'], 'department' => $r['department']], $arch);

        return ActionResult::ok('Enrollment token created. Copy the commands now: the token is shown only once.', 201, 'created',
            ['token' => $r['token'], 'token_plain' => $r['token_plain'], 'commands' => $commands, 'department' => $r['department']]);
    }

    // ------------------------------------------------------------------

    private function deny(RmmPrincipal $by, string $ability, int $clientId = 0, bool $requireEnabled = false): ?ActionResult
    {
        $denied = $this->authz->check($by->userId, $ability, $clientId, $requireEnabled);

        return $denied === null ? null : ActionResult::fail(403, 'forbidden', $denied);
    }
}
