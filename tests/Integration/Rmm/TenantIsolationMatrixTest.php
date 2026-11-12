<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Authz\RmmAbility as A;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Rmm\Technician\ActionResult;
use RivetCore\Tests\Support\TenantMatrixEnv as E;

/**
 * The client-scope (tenant isolation) test matrix.
 *
 * MeshCentral does not separate clients: one Mesh account sees every device of the server, so Core's own client scoping is the only barrier between
 * one client's devices and a technician who works for another. This test proves it for EVERY public method of the action and service entry points that
 * takes a device id or an id derived from a device (job, transfer, alert, approval, package assignment, schedule, policy assignment, token, rule, window,
 * VM ...) and for every id-bearing REST route.
 *
 * The principal is user 20: it holds every rmm.* ability, restricted by the tenancy to client A. For each row:
 *
 *  - FOREIGN (the target belongs to client B) and MISSING (the id does not exist) must both be REFUSED (403/404, a few documented 409/422), with the SAME
 *    answer (status, code and text: no existence oracle), and with NO side effect: not one table of the module, no audit row, no event, no remote session,
 *    no request that reached the (mock) MeshCentral server, no file in the transfer folder, no change in the bridge ({@see E::snapshot()}).
 *  - OWN (a fresh object of client A) is the POSITIVE CONTROL: the same principal succeeds, and, for a row that writes, the snapshot DOES change. That
 *    keeps the test from passing vacuously (a broken fixture, a method that always refuses, a snapshot that cannot see).
 *
 * Objects no client owns (the script library, the package catalog, tags, groups, patch policies and rings, custom field definitions, script policies) are
 * "fleet-wide": a caller limited to some clients may not change them at all, for FOREIGN, MISSING and OWN alike, and the control runs as the unrestricted
 * administrator (row option `ctl => admin`).
 *
 * `testEveryIdBearingMethodIsInTheMatrix` is the COVERAGE GUARD: a public method that takes an id-like parameter must have a row or an entry of
 * {@see self::NOT_CLIENT_SCOPED} with the reason. docs/modules/rmm.md ("Client scoping matrix") lists the same methods; a test keeps the two in step.
 */
final class TenantIsolationMatrixTest extends TestCase
{
    /** Every class whose public methods the guard walks. */
    private const CLASSES = [
        \RivetCore\Rmm\Admin\RmmAdmin::class, \RivetCore\Rmm\Technician\TechnicianActions::class, \RivetCore\Rmm\Technician\ScriptActions::class,
        \RivetCore\Rmm\Deploy\DeployActions::class, \RivetCore\Rmm\Remote\RemoteActions::class, \RivetCore\Rmm\Patch\PatchActions::class,
        \RivetCore\Rmm\Remote\TransferService::class, \RivetCore\Rmm\Patch\PatchApprovalService::class, \RivetCore\Rmm\Patch\PatchInstallService::class,
        \RivetCore\Rmm\Alerting\AlertingActions::class, \RivetCore\Rmm\Change\ChangeActions::class, \RivetCore\Rmm\Logs\LogActions::class,
        \RivetCore\Rmm\Hypervisor\HypervisorActions::class, \RivetCore\Rmm\AutoInstall\AutoInstallActions::class, \RivetCore\Rmm\Technician\InventoryActions::class,
        \RivetCore\Rmm\Technician\FieldActions::class, \RivetCore\Rmm\Technician\PolicyActions::class,
        \RivetCore\Rmm\Http\TechnicianApi::class, \RivetCore\Rmm\Http\AlertingApi::class, \RivetCore\Rmm\Http\ChangeApi::class, \RivetCore\Rmm\Http\DeployApi::class,
        \RivetCore\Rmm\Http\PatchApi::class, \RivetCore\Rmm\Http\RemoteApi::class, \RivetCore\Rmm\Http\HypervisorApi::class, \RivetCore\Rmm\Http\AutoInstallApi::class,
        \RivetCore\Rmm\Http\StarterApi::class,
    ];

    /**
     * Public methods with an id-like parameter that are legitimately NOT client-scoped, each with the reason. Everything else must be in the matrix.
     * The guard also fails on an entry that is stale (no such method) or that is also in the matrix.
     */
    private const NOT_CLIENT_SCOPED = [
        // ---- fleet-wide objects of the catalog: no client owns them
        'DeployActions::package' => 'Reads one package of the catalog, which is shared by every client; the installer address is masked for a caller who is not fleet-wide. Writes are in the matrix.',
        'AutoInstallActions::paramsOf' => 'Reads where the parameters of a catalog package are set (never a value); the client-scoped entries are filtered to the caller\'s clients. Writes are in the matrix.',
        'PatchActions::getPolicy' => 'Reads one patch policy, a fleet-wide object: settings only. Its assignments are filtered to the caller\'s clients (testPolicyDetailsHideTheAssignmentsOfOtherClients); its writes are in the matrix.',
        // ---- RmmAdmin: fleet-wide agent releases and installer templates, gated by an administrative ability (no client or device is named)
        'RmmAdmin::msiTemplateAction' => 'Activates or retires an MSI installer template: a fleet-wide release asset no client owns; needs rmm.binary.publish.',
        'RmmAdmin::binaryAction' => 'Publishes, promotes or retires a hosted agent binary: a fleet-wide release asset no client owns; needs rmm.binary.publish.',
        'RmmAdmin::updateRelease' => 'Changes the rollout percentage of an agent release: fleet-wide, no client owns it; needs rmm.binary.publish.',
        // ---- services below the actions: no principal, they act on a device row the caller already authorised
        'TransferService::listFor' => 'Service layer, no principal: RemoteActions::listTransfers() resolves the device and the ability first (matrix row).',
        'TransferService::receiveChunk' => 'Agent-facing: the device is authenticated by its own token and the transfer by a one-time token; find() is keyed by (transfer, device), see TransferService::find.',
        'TransferService::serveChunk' => 'Agent-facing: the device is authenticated by its own token and the transfer by a one-time token; find() is keyed by (transfer, device), see TransferService::find.',
        'TransferService::storeUploadContent' => 'Service layer: RemoteActions::uploadContent() resolves the device and the ability first (matrix row).',
        'PatchApprovalService::saveRule' => 'Service layer: PatchActions::saveRule() applies the client scope first (matrix rows).',
        'PatchApprovalService::deleteRule' => 'Service layer: PatchActions::deleteRule() applies the client scope first (matrix row).',
        'PatchApprovalService::rule' => 'Service layer lookup; PatchActions::saveRule()/deleteRule() filter on the rule\'s client before it is used.',
        'PatchApprovalService::applyApproved' => 'Applies a frozen approval request after PatchActions::decideApproval() checked the approver against every client of the request.',
        'PatchApprovalService::approve' => 'Service layer: PatchActions::approve() selects only rows inside the caller\'s scope first (PatchApprovalService::select is in the matrix).',
        'PatchApprovalService::decline' => 'Service layer: PatchActions::decline() selects only rows inside the caller\'s scope first.',
        'PatchApprovalService::requestSecond' => 'Service layer: called with rows PatchActions::approve() already scoped.',
        'PatchApprovalService::autoApprove' => 'Server-side rule engine (scheduler), no principal; it works device by device on the rules of the device\'s own client.',
        'PatchInstallService::queue' => 'Service layer: PatchActions::install() resolves the device, the client scope and the ability first (matrix rows).',
        'PatchInstallService::applyJob' => 'Agent-facing: applies the report of a job to the device that authenticated.',
        // ---- the Mesh install after approval: server side, system principal
        'RemoteActions::installMeshAgentAfterApproval' => 'Server-side (MeshAfterApproval), no principal: the administrator\'s tick-box on the installer was the decision; it takes the device row of the device that was just approved.',
    ];

    /** Route words that carry no id (settings, lists, catalogs, verbs): the route guard does not ask for a row for them. */
    private const ROUTE_WORDS_WITHOUT_ID = [
        'settings', 'stats', 'catalog', 'plan', 'compliance', 'check_types', 'groups', 'templates', 'selectable', 'software_hosts', 'software_report', 'csv', 'devices', 'refresh', 'values',
        'policy', 'live', 'network', 'jobs', 'resign', 'restore', 'versions', 'runs', 'status', 'timeline', 'node', 'query', 'content', 'download', 'upload', 'install', 'uninstall',
        'history', 'scan', 'approve', 'decline', 'reject', 'cancel', 'ack', 'kill', 'thresholds', 'alerting', 'policies', 'rules', 'rings', 'hypervisor', 'checks', 'tags', 'software',
        'assignments', 'resolve', 'remote', 'mesh', 'changes', 'fields', 'patches', 'deployments', 'autoinstall', 'approvals', 'schedules', 'scripts', 'logs', 'files', 'processes', 'services',
        'software_files', 'software_params', 'software_templates', 'software_autoinstall', 'software_assignments', 'software_packages', 'patch_policies', 'escalation_policies', 'maintenance',
        'alerts', 'vms', 'parent',
    ];

    private static ?E $env = null;

    protected function setUp(): void
    {
        if (!getenv('RIVETCORE_TEST_DB_NAME') || !str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
            self::markTestSkipped('RIVETCORE_TEST_DB_NAME must name a scratch database.');
        }
        ini_set('error_log', '/dev/null');
    }

    public static function tearDownAfterClass(): void
    {
        self::$env?->close();
        self::$env = null;
    }

    private static function env(): E
    {
        return self::$env ??= new E();
    }

    // ------------------------------------------------------------------ the matrix

    /**
     * One row of the matrix.
     *
     * @param list<string> $ab abilities the principal holds and the method asks for
     * @param bool $w the method writes (the control must change the snapshot)
     * @param \Closure(E,string,RmmPrincipal):\Closure():mixed $call builds the arguments for a target (setup runs before the snapshot), returns the call
     * @param array<string,mixed> $o refuse: statuses accepted as a refusal (default 403, 404); control: statuses accepted for the control (default: a success);
     *        ctl: `admin` = the control runs as the unrestricted administrator (fleet-wide objects a client-restricted caller may not change); list: a read that
     *        filters instead of refusing (200 with nothing of client B in it)
     * @return array<string,mixed>
     */
    private static function row(string $m, array $ab, string $where, bool $w, \Closure $call, array $o = []): array
    {
        return ['m' => $m, 'ab' => $ab, 'where' => $where, 'w' => $w, 'call' => $call] + $o;
    }

    /** @return array<string,array<string,mixed>> */
    public static function rows(): array
    {
        static $rows = null;
        if ($rows !== null) {
            return $rows;
        }
        $rows = [];
        foreach ([...self::technicianRows(), ...self::scriptRows(), ...self::deployRows(), ...self::remoteRows(), ...self::patchRows(), ...self::alertingRows(),
            ...self::logChangeHypervisorRows(), ...self::inventoryRows(), ...self::adminRows(), ...self::restRows()] as $r) {
            $name = $r['name'] ?? $r['m'];
            if (isset($rows[$name])) {
                throw new \LogicException("duplicate matrix row $name");
            }
            $rows[$name] = $r;
        }

        return $rows;
    }

    /** @return array<string,array{0:string}> */
    public static function matrix(): array
    {
        $out = [];
        foreach (array_keys(self::rows()) as $name) {
            $out[$name] = [$name];
        }

        return $out;
    }

    /** @return array{0:int,1:string,2:string,3:bool} status, code, text, success */
    private static function out(mixed $r): array
    {
        if ($r instanceof ActionResult) {
            return [$r->http, $r->code, $r->message, $r->ok];
        }
        if ($r instanceof RmmResponse) {
            $b = json_decode((string) $r->body, true);

            return [$r->status, is_array($b) ? (string) ($b['code'] ?? '') : '', is_array($b) ? (string) ($b['error'] ?? '') : '', $r->status < 300];
        }
        throw new \LogicException('a matrix row must return an ActionResult or an RmmResponse, got ' . get_debug_type($r));
    }

    /** A lookup that answers null when the caller may not see it. @param array<mixed>|null $x */
    private static function found(?array $x): ActionResult
    {
        return $x === null ? ActionResult::fail(404, 'not_found', 'Not found.') : ActionResult::ok('ok');
    }

    /** A refusal text (null = allowed) as a result. */
    private static function refusal(?string $why): ActionResult
    {
        return $why === null ? ActionResult::ok('ok') : ActionResult::fail(403, 'forbidden', $why);
    }

    private static function ta(E $e): \RivetCore\Rmm\Technician\TechnicianActions
    {
        return $e->h->module->technician();
    }

    /** @return list<array<string,mixed>> */
    private static function technicianRows(): array
    {
        $V = A::DEVICE_VIEW;
        $mk = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('TechnicianActions::' . $m, $ab, $where, $w, $f, $o);

        return [
            $mk('visibleDevice', [$V], 'visibleDevice(): authz->check(DEVICE_VIEW, client) incl. clientOk -> null', false,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => self::found(self::ta($e)->visibleDevice($p->userId, $e->dev($t)))),
            $mk('submitJob', [A::JOB_RUN_SCRIPT], 'deviceFor(): clientOk + DEVICE_VIEW(client) -> 404, then the ability for the client', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => self::ta($e)->submitJob($p, $e->dev($t), ['type' => 'powershell', 'script' => 'Get-Date'])),
            $mk('submitJob#saved', [A::JOB_RUN_SAVED], 'deviceFor() as above (saved script path)', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => self::ta($e)->submitJob($p, $e->dev($t), ['type' => 'powershell', 'script_id' => $e->savedScript])),
            $mk('submitJob#reboot', [A::JOB_REBOOT], 'deviceFor() as above (reboot)', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => self::ta($e)->submitJob($p, $e->dev($t), ['type' => 'reboot', 'confirm' => true])),
            $mk('submitJob#library', [A::JOB_RUN_SAVED], 'delegates to ScriptActions::runOnDevice()', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => self::ta($e)->submitJob($p, $e->dev($t), ['library_script_id' => $e->scriptId, 'type' => 'powershell'])),
            $mk('cancelJob', [A::JOB_RUN_SAVED], 'deviceFor() (404), then the job is looked up by (job, device)', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    [$d, $j] = [$e->dev($t), $e->get($t, 'job')];

                    return fn () => self::ta($e)->cancelJob($p, $d, $j);
                }),
            $mk('cancelJob#cross', [A::JOB_RUN_SAVED], 'own device + the job of another client: the job is looked up by (job, device), so it is not found', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $j = $e->get($t, 'job');

                    return fn () => self::ta($e)->cancelJob($p, $e->devA, $j);
                }, ['refuse' => [404, 409]]),
            $mk('launchRemote', [A::REMOTE_LAUNCH], 'deviceFor() before any Mesh call, session or token', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => self::ta($e)->launchRemote($p, $e->dev($t), false, '203.0.113.9', 'test-agent', 'desktop')),
            $mk('launchRemote#forced', [A::REMOTE_LAUNCH], 'deviceFor() (force only skips the offline check)', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => self::ta($e)->launchRemote($p, $e->dev($t), true, '203.0.113.9', 'test-agent', 'desktop')),
            $mk('launchRemote#terminal', [A::REMOTE_LAUNCH, A::REMOTE_MANAGE], 'deviceFor() before the remote.manage and feature checks', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => self::ta($e)->launchRemote($p, $e->dev($t), false, '203.0.113.9', 'test-agent', 'terminal')),
            $mk('launchRemote#files', [A::REMOTE_LAUNCH, A::REMOTE_MANAGE], 'deviceFor() before the remote.manage and feature checks', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => self::ta($e)->launchRemote($p, $e->dev($t), false, '203.0.113.9', 'test-agent', 'files')),
            $mk('setMeshNode', [A::DEVICE_MANAGE], 'manage(): deviceFor(administrative) -> 404', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $d = $e->get($t, 'device');

                    return fn () => self::ta($e)->setMeshNode($p, $d, 'node//' . str_repeat('B', 24) . '9');
                }),
            $mk('resolvePending', [A::DEVICE_MANAGE], 'manage()', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $d = $e->get($t, 'pending');

                    return fn () => self::ta($e)->resolvePending($p, $d, 'reject');
                }),
            $mk('resolvePending#link', [A::DEVICE_MANAGE], 'manage(), then the asset\'s client must be one the caller manages (CORE-02)', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $d = $e->get('own', 'pending');
                    $asset = $t === E::MISSING ? 99999 : $e->newAsset($e->client($t));

                    return fn () => self::ta($e)->resolvePending($p, $d, 'link', $asset);
                }, ['refuse' => [404, 409]]),
            $mk('revoke', [A::DEVICE_MANAGE], 'manage()', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $d = $e->get($t, 'device');

                    return fn () => self::ta($e)->revoke($p, $d, 'matrix');
                }),
            $mk('rotateCredential', [A::DEVICE_MANAGE], 'manage()', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $d = $e->get($t, 'device');

                    return fn () => self::ta($e)->rotateCredential($p, $d);
                }),
            $mk('retire', [A::DEVICE_MANAGE], 'manage()', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $d = $e->get($t, 'device');

                    return fn () => self::ta($e)->retire($p, $d);
                }),
            $mk('allowReenroll', [A::DEVICE_MANAGE], 'manage()', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $d = $e->get($t, 'device');
                    if ($t === E::OWN) {
                        self::ta($e)->retire($e->admin, $d);   // re-enrolling needs a retired or revoked device
                    }

                    return fn () => self::ta($e)->allowReenroll($p, $d);
                }),
            $mk('setRing', [A::DEVICE_MANAGE], 'manage()', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $d = $e->get($t, 'device');

                    return fn () => self::ta($e)->setRing($p, $d, 'pilot');
                }),
            $mk('setAutomationDisabled', [A::DEVICE_MANAGE], 'manage()', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $d = $e->get($t, 'device');

                    return fn () => self::ta($e)->setAutomationDisabled($p, $d, true);
                }),
            $mk('clearUpdateFailures', [A::DEVICE_MANAGE], 'manage()', false,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => self::ta($e)->clearUpdateFailures($p, $e->dev($t))),
            $mk('transfer', [A::DEVICE_MANAGE], 'manage() on the device (404): a device of another client is never reached', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $d = $e->get($t, 'device');

                    return fn () => self::ta($e)->transfer($p, $d, $e->clientA());
                }),
            $mk('transfer#target', [A::DEVICE_MANAGE], 'manage() on the device, then the TARGET client: clientOk + DEVICE_MANAGE(target) -> 403', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $d = $e->get('own', 'device');

                    return fn () => self::ta($e)->transfer($p, $d, $e->client($t));
                }),
            $mk('createToken', [A::TOKEN_MANAGE], 'authz->check(TOKEN_MANAGE, client) incl. clientOk -> 403', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => self::ta($e)->createToken($p, $e->client($t), 0, 'stable', 24, 5, 'matrix')),
            $mk('revokeToken', [A::TOKEN_MANAGE], 'the token\'s client is checked (clientOk + TOKEN_MANAGE) before it is revoked: missing and foreign both 404', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $k = $e->get($t, 'token');

                    return fn () => self::ta($e)->revokeToken($p, $k);
                }),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function scriptRows(): array
    {
        $mk = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('ScriptActions::' . $m, $ab, $where, $w, $f, $o);
        $sa = static fn (E $e) => $e->h->module->scriptActions();
        $fleet = ['ctl' => 'admin'];

        return [
            $mk('updateScript', [A::JOB_RUN_SCRIPT], 'fleetWide(): a caller limited to some clients may not change library scripts (shared by every client)', true,
                function (E $e, string $t, RmmPrincipal $p) use ($sa) {
                    $s = $e->get($t, 'script');

                    return fn () => $sa($e)->updateScript($p, $s, ['description' => 'matrix']);
                }, $fleet),
            $mk('retireScript', [A::JOB_RUN_SCRIPT], 'fleetWide()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($sa) {
                    $s = $e->get($t, 'script');

                    return fn () => $sa($e)->retireScript($p, $s, true);
                }, $fleet),
            $mk('resignScript', [A::ADMIN], 'fleetWide() (re-signing rewrites the signature every client\'s devices verify)', true,
                function (E $e, string $t, RmmPrincipal $p) use ($sa) {
                    $s = $e->get($t, 'script');

                    return fn () => $sa($e)->resignScript($p, $s);
                }, $fleet),
            $mk('runOnDevice', [A::JOB_RUN_SAVED], 'device view check (404) before the ability and the script', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $sa($e)->runOnDevice($p, $e->dev($t), ['library_script_id' => $e->scriptId])),
            $mk('runOnTarget#device', [A::JOB_RUN_SAVED], 'TargetResolver counts only devices inside visibleClientIds(): nothing to run on', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $sa($e)->runOnTarget($p, ['library_script_id' => $e->scriptId, 'target' => ['type' => 'device', 'id' => $e->dev($t)]]), ['refuse' => [403, 404, 422]]),
            $mk('runOnTarget#client', [A::JOB_RUN_SAVED], 'TargetResolver counts only devices inside visibleClientIds()', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $sa($e)->runOnTarget($p, ['library_script_id' => $e->scriptId, 'target' => ['type' => 'client', 'id' => $e->client($t)]]), ['refuse' => [403, 404, 422]]),
            $mk('decide', [A::JOB_APPROVE], 'the approval is visible only when its target lies inside the caller\'s clients (approvalVisible); approving also checks approverScope', true,
                function (E $e, string $t, RmmPrincipal $p) use ($sa) {
                    $a = $e->get($t, 'approval');

                    return fn () => $sa($e)->decide($p, $a, true, 'matrix');
                }),
            $mk('decide#reject', [A::JOB_APPROVE], 'rejecting needs the same visibility as approving', true,
                function (E $e, string $t, RmmPrincipal $p) use ($sa) {
                    $a = $e->get($t, 'approval');

                    return fn () => $sa($e)->decide($p, $a, false, 'matrix');
                }),
            $mk('decide#software', [A::JOB_APPROVE, A::SOFTWARE_DEPLOY], 'delegates to DeployActions::decideApproval(): every client of the request', true,
                function (E $e, string $t, RmmPrincipal $p) use ($sa) {
                    $a = $e->get($t, 'approval_sw');

                    return fn () => $sa($e)->decide($p, $a, true, 'matrix');
                }),
            $mk('decide#patch', [A::JOB_APPROVE, A::PATCH_MANAGE], 'delegates to PatchActions::decideApproval(): every client of the request', true,
                function (E $e, string $t, RmmPrincipal $p) use ($sa) {
                    $a = $e->get($t, 'approval_patch');

                    return fn () => $sa($e)->decide($p, $a, true, 'matrix');
                }),
            $mk('cancel', [A::JOB_APPROVE], 'the requester or an administrator withdraws a request; an administrator limited to some clients only a visible one', true,
                function (E $e, string $t, RmmPrincipal $p) use ($sa) {
                    $a = $e->get($t, 'approval');

                    return fn () => $sa($e)->cancel($p, $a);
                }),
            $mk('approvalVisible', [A::JOB_APPROVE], 'approvalVisible(): target inside visibleClientIds()', false,
                function (E $e, string $t, RmmPrincipal $p) use ($sa) {
                    $id = $e->get($t, 'approval');
                    $row = $e->h->module->approvals()->get($id);

                    return fn () => $row === null ? ActionResult::fail(404, 'not_found', 'Not found.') : ($sa($e)->approvalVisible($p->userId, $row) ? ActionResult::ok('ok') : ActionResult::fail(404, 'not_found', 'Not found.'));
                }),
            $mk('saveSchedule', [A::ADMIN], 'an existing schedule of a client the caller cannot see is 404; a schedule is scoped by its target', true,
                function (E $e, string $t, RmmPrincipal $p) use ($sa) {
                    $s = $e->get($t, 'schedule');

                    return fn () => $sa($e)->saveSchedule($p, $s, ['interval_s' => 7200]);
                }),
            $mk('saveSchedule#create', [A::ADMIN], 'a new schedule may only target devices inside the caller\'s clients', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $sa($e)->saveSchedule($p, null, ['name' => 'N' . random_int(1, 1 << 30), 'library_script_id' => $e->scriptId, 'target' => ['type' => 'client', 'id' => $e->client($t)],
                    'kind' => 'interval', 'interval_s' => 3600, 'start_in_s' => 0]), ['refuse' => [403, 404, 422]]),
            $mk('deleteSchedule', [A::ADMIN], 'a schedule of a client the caller cannot see is 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($sa) {
                    $s = $e->get($t, 'schedule');

                    return fn () => $sa($e)->deleteSchedule($p, $s);
                }),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function deployRows(): array
    {
        $mk = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('DeployActions::' . $m, $ab, $where, $w, $f, $o);
        $da = static fn (E $e) => $e->h->module->deployActions();
        $fleet = ['ctl' => 'admin'];

        return [
            $mk('savePackage', [A::SOFTWARE_MANAGE], 'unrestricted(): a catalog write needs a caller whose access is not limited to some clients', true,
                function (E $e, string $t, RmmPrincipal $p) use ($da) {
                    $k = $e->get($t, 'package');

                    return fn () => $da($e)->savePackage($p, $k, ['name' => 'Renamed ' . $k]);
                }, $fleet),
            $mk('deletePackage', [A::SOFTWARE_MANAGE], 'unrestricted()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($da) {
                    $k = $e->get($t, 'package');

                    return fn () => $da($e)->deletePackage($p, $k);
                }, $fleet),
            $mk('addVersion', [A::SOFTWARE_MANAGE], 'unrestricted()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($da) {
                    $k = $e->get($t, 'package');

                    return fn () => $da($e)->addVersion($p, $k, ['version' => '9.9.9', 'os' => 'windows', 'fmt' => 'msi', 'url' => 'https://' . E::HOST . '/x.msi', 'sha256' => E::SHA, 'size_bytes' => 10,
                        'product_code' => '{11111111-2222-3333-4444-555555555555}', 'detect_min' => '9.9.9']);
                }, $fleet),
            $mk('retireVersion', [A::SOFTWARE_MANAGE], 'unrestricted()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($da) {
                    $k = $e->get($t, 'version');

                    return fn () => $da($e)->retireVersion($p, $k, true);
                }, $fleet),
            $mk('addHost', [A::ADMIN], 'unrestricted() (a download host is allowed for every client)', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $da($e)->addHost($p, ['host' => 'matrix' . random_int(1, 1 << 30) . '.example.test']), $fleet),
            $mk('removeHost', [A::ADMIN], 'unrestricted()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($da) {
                    $host = 'gone' . random_int(1, 1 << 30) . '.example.test';
                    $da($e)->addHost($e->admin, ['host' => $host]);

                    return fn () => $da($e)->removeHost($p, $host);
                }, $fleet),
            $mk('assign#device', [A::SOFTWARE_DEPLOY], 'a device scope must be a device inside the caller\'s clients (404)', true,
                function (E $e, string $t, RmmPrincipal $p) use ($da) {
                    $pk = $e->newPackage();

                    return fn () => $da($e)->assign($p, ['package_id' => $pk, 'scope_type' => 'device', 'scope_id' => $e->dev($t), 'action' => 'install']);
                }),
            $mk('assign#client', [A::SOFTWARE_DEPLOY], 'a client scope must be a client inside the caller\'s clients (404)', true,
                function (E $e, string $t, RmmPrincipal $p) use ($da) {
                    $pk = $e->newPackage();

                    return fn () => $da($e)->assign($p, ['package_id' => $pk, 'scope_type' => 'client', 'scope_id' => $e->client($t), 'action' => 'install']);
                }),
            $mk('unassign#device', [A::SOFTWARE_DEPLOY], 'assignmentVisible(): the device of the assignment must be inside the caller\'s clients', true,
                function (E $e, string $t, RmmPrincipal $p) use ($da) {
                    $a = $e->get($t, 'assign_device');

                    return fn () => $da($e)->unassign($p, $a);
                }),
            $mk('unassign#client', [A::SOFTWARE_DEPLOY], 'assignmentVisible(): the client scope must be inside the caller\'s clients', true,
                function (E $e, string $t, RmmPrincipal $p) use ($da) {
                    $a = $e->get($t, 'assign_client');

                    return fn () => $da($e)->unassign($p, $a);
                }),
            $mk('deviceStatus', [A::SOFTWARE_VIEW], 'deviceFor(): clientOk + DEVICE_VIEW + SOFTWARE_VIEW for the client -> 404', false,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $da($e)->deviceStatus($p, $e->dev($t))),
            $mk('deviceHistory', [A::SOFTWARE_VIEW], 'deviceFor()', false,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $da($e)->deviceHistory($p, $e->dev($t), 10, 0)),
            $mk('detect', [A::SOFTWARE_MANAGE], 'deviceFor() before the queue', false,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $da($e)->detect($p, $e->dev($t))),
            $mk('run#dry', [A::SOFTWARE_MANAGE], 'deviceFor()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($da) {
                    $d = $e->get($t, 'device');

                    return fn () => $da($e)->run($p, $d, ['package_id' => $e->packageId, 'mode' => 'dry_run']);
                }),
            $mk('run#install', [A::SOFTWARE_DEPLOY], 'deviceFor()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($da) {
                    $d = $e->get($t, 'device');

                    return fn () => $da($e)->run($p, $d, ['package_id' => $e->packageId, 'mode' => 'run', 'confirm' => true]);
                }),
            $mk('report', [A::SOFTWARE_VIEW], 'a client filter outside the caller\'s clients is 404', false,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $da($e)->report($p, ['client_id' => $e->client($t)])),
            $mk('reportCsv', [A::SOFTWARE_VIEW], 'report()', false,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $da($e)->reportCsv($p, ['client_id' => $e->client($t)])),
            $mk('decideApproval', [A::SOFTWARE_DEPLOY], 'every client of the frozen request must be inside the caller\'s scope BEFORE the decision closure runs', true,
                function (E $e, string $t, RmmPrincipal $p) use ($da) {
                    $id = $e->get($t, 'approval_sw');
                    $row = $e->h->module->approvals()->get($id) ?? ['approval_id' => $id, 'summary' => '', 'requested_by' => 21, 'kind' => 'software'];
                    $req = $e->h->module->approvals()->requestOf($id) ?? [];
                    $decide = $t === E::OWN ? fn (): array => $e->h->module->approvals()->decide($id, $p->userId, true, '')
                        : static function (): array {
                            throw new \LogicException('the decision closure must not run for a request of another client');
                        };

                    return fn () => $da($e)->decideApproval($p, $row, $req, true, $decide);
                }),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function remoteRows(): array
    {
        $mk = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('RemoteActions::' . $m, $ab, $where, $w, $f, $o);
        $ra = static fn (E $e) => $e->h->module->remoteActions();
        $dv = static fn (string $m, string $ab, string $what, bool $w, \Closure $f, array $o = []): array => $mk($m, [$ab], $what, $w, $f, $o);
        $stream = static function (): mixed {
            $s = fopen('php://memory', 'w+b');
            fwrite($s, 'hello');
            rewind($s);

            return $s;
        };

        return [
            $dv('listServices', A::REMOTE_MANAGE, 'gate(): deviceFor() (404) before the ability, the feature and the queue', true, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->listServices($p, $e->dev($t))),
            $dv('controlService', A::REMOTE_MANAGE, 'gate()', true, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->controlService($p, $e->dev($t), 'Spooler', 'start', false)),
            $dv('listProcesses', A::REMOTE_MANAGE, 'gate()', true, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->listProcesses($p, $e->dev($t))),
            $dv('killProcess', A::REMOTE_MANAGE, 'gate()', true, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->killProcess($p, $e->dev($t), 4242, 'notepad.exe', false, true)),
            $dv('snapshot', A::REMOTE_MANAGE, 'deviceFor() (404) then remote.manage', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->snapshot($p, $e->dev($t), 'services')),
            $dv('jobResult', A::REMOTE_MANAGE, 'deviceFor(); the job is looked up by (job, device)', false,
                function (E $e, string $t, RmmPrincipal $p) use ($ra) {
                    [$d, $j] = [$e->dev($t), $e->get($t, 'tooljob')];

                    return fn () => $ra($e)->jobResult($p, $d, $j);
                }),
            $dv('jobResult#cross', A::REMOTE_MANAGE, 'own device + the job of another client: looked up by (job, device), not found', false,
                function (E $e, string $t, RmmPrincipal $p) use ($ra) {
                    $j = $e->get($t, 'tooljob');

                    return fn () => $ra($e)->jobResult($p, $e->devA, $j);
                }),
            $dv('requestDownload', A::REMOTE_MANAGE, 'gate()', true, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->requestDownload($p, $e->dev($t), 'C:\\Temp\\report.csv')),
            $dv('createUpload', A::ADMIN, 'gate()', true, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->createUpload($p, $e->dev($t), 'C:\\Temp\\up.txt', 5, hash('sha256', 'hello'), false, true)),
            $dv('uploadContent', A::ADMIN, 'gate(); the transfer is looked up by (transfer, device)', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ra, $stream) {
                    [$d, $x] = [$e->dev($t), $e->get($t, 'transfer')];

                    return fn () => $ra($e)->uploadContent($p, $d, $x, $stream());
                }),
            $dv('uploadContent#cross', A::ADMIN, 'own device + the transfer of another client: looked up by (transfer, device), not found', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ra, $stream) {
                    $x = $e->get($t, 'transfer');

                    return fn () => $ra($e)->uploadContent($p, $e->devA, $x, $stream());
                }),
            $dv('listTransfers', A::REMOTE_MANAGE, 'deviceFor() (404) then remote.manage', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->listTransfers($p, $e->dev($t))),
            $dv('transfer', A::REMOTE_MANAGE, 'transferOf(): deviceFor() then the transfer by (transfer, device)', false,
                function (E $e, string $t, RmmPrincipal $p) use ($ra) {
                    [$d, $x] = [$e->dev($t), $e->get($t, 'transfer')];

                    return fn () => $ra($e)->transfer($p, $d, $x);
                }),
            $dv('transfer#cross', A::REMOTE_MANAGE, 'own device + the transfer of another client: not found', false,
                function (E $e, string $t, RmmPrincipal $p) use ($ra) {
                    $x = $e->get($t, 'transfer');

                    return fn () => $ra($e)->transfer($p, $e->devA, $x);
                }),
            $dv('downloadContent', A::REMOTE_MANAGE, 'transferOf()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ra) {
                    [$d, $x] = [$e->dev($t), $e->get($t, 'transfer')];

                    return fn () => $ra($e)->downloadContent($p, $d, $x);
                }, ['control' => [409]]),
            $dv('downloadContent#cross', A::REMOTE_MANAGE, 'own device + the transfer of another client: not found', false,
                function (E $e, string $t, RmmPrincipal $p) use ($ra) {
                    $x = $e->get($t, 'transfer');

                    return fn () => $ra($e)->downloadContent($p, $e->devA, $x);
                }, ['control' => [409]]),
            $dv('deleteTransfer', A::REMOTE_MANAGE, 'transferOf()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ra) {
                    [$d, $x] = [$e->dev($t), $e->get($t, 'transfer')];

                    return fn () => $ra($e)->deleteTransfer($p, $d, $x);
                }),
            $dv('deleteTransfer#cross', A::REMOTE_MANAGE, 'own device + the transfer of another client: not found', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ra) {
                    $x = $e->get($t, 'transfer');

                    return fn () => $ra($e)->deleteTransfer($p, $e->devA, $x);
                }),
            $dv('meshInfo', A::REMOTE_LAUNCH, 'deviceFor() (404) before any node is revealed', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->meshInfo($p, $e->dev($t))),
            $dv('headerRemote', A::REMOTE_LAUNCH, 'deviceFor() (404)', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->headerRemote($p, $e->dev($t))),
            $dv('installMeshAgent', A::REMOTE_MANAGE, 'gate()', true, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->installMeshAgent($p, $e->dev($t), true), ['mesh' => 'https']),
            $dv('uninstallMeshAgent', A::REMOTE_MANAGE, 'gate()', true, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->uninstallMeshAgent($p, $e->dev($t), true)),
            $dv('installMeshAgentBulk#device', A::REMOTE_MANAGE, 'TargetResolver counts only devices inside visibleClientIds()', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->installMeshAgentBulk($p, ['target' => ['type' => 'device', 'id' => $e->dev($t)], 'force' => true, 'confirm' => true]), ['refuse' => [403, 404, 422], 'mesh' => 'https']),
            $dv('installMeshAgentBulk#client', A::REMOTE_MANAGE, 'TargetResolver counts only devices inside visibleClientIds()', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $ra($e)->installMeshAgentBulk($p, ['target' => ['type' => 'client', 'id' => $e->client($t)], 'force' => true, 'confirm' => true]), ['refuse' => [403, 404, 422], 'mesh' => 'https']),
            self::row('TransferService::find', [], 'keyed by (transfer, device): the transfer of another device is never returned for this one', false,
                function (E $e, string $t, RmmPrincipal $p) {
                    $x = $e->get($t, 'transfer');

                    return fn () => self::found($e->h->module->transfers()->find(strtolower($x), $e->devA));
                }),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function patchRows(): array
    {
        $mk = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('PatchActions::' . $m, $ab, $where, $w, $f, $o);
        $pa = static fn (E $e) => $e->h->module->patchActions();
        $fleet = ['ctl' => 'admin'];

        return [
            $mk('summary', [A::PATCH_VIEW], 'deviceFor(): clientOk + DEVICE_VIEW + PATCH_VIEW for the client -> 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $pa($e)->summary($p, $e->dev($t))),
            $mk('updates', [A::PATCH_VIEW], 'deviceFor()', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $pa($e)->updates($p, $e->dev($t), [], 10, 0)),
            $mk('history', [A::PATCH_VIEW], 'deviceFor()', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $pa($e)->history($p, $e->dev($t), 10, 0)),
            $mk('scan', [A::PATCH_MANAGE], 'deviceFor() before the queue', true,
                function (E $e, string $t, RmmPrincipal $p) use ($pa) {
                    $d = $e->get($t, 'device');

                    return fn () => $pa($e)->scan($p, $d);
                }),
            $mk('install#dry', [A::PATCH_MANAGE], 'deviceFor()', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $pa($e)->install($p, $e->dev($t), ['mode' => 'dry_run'])),
            $mk('install#install', [A::PATCH_INSTALL], 'deviceFor()', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $pa($e)->install($p, $e->dev($t), ['mode' => 'install', 'confirm' => true])),
            $mk('approve#device', [A::PATCH_MANAGE], 'a named device goes through deviceFor() (404)', true,
                function (E $e, string $t, RmmPrincipal $p) use ($pa) {
                    $e->resetPatches();

                    return fn () => $pa($e)->approve($p, ['update_ids' => [E::WIN_UPDATE], 'device_id' => $e->dev($t)]);
                }),
            $mk('approve#client', [A::PATCH_MANAGE], 'a client filter outside visibleClientIds() is 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($pa) {
                    $e->resetPatches();

                    return fn () => $pa($e)->approve($p, ['update_ids' => [E::WIN_UPDATE], 'client_id' => $e->client($t)]);
                }),
            $mk('decline#device', [A::PATCH_MANAGE], 'a named device goes through deviceFor() (404)', true,
                function (E $e, string $t, RmmPrincipal $p) use ($pa) {
                    $e->resetPatches();

                    return fn () => $pa($e)->decline($p, ['update_ids' => [E::WIN_UPDATE], 'device_id' => $e->dev($t)]);
                }),
            $mk('decline#client', [A::PATCH_MANAGE], 'a client filter outside visibleClientIds() is 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($pa) {
                    $e->resetPatches();

                    return fn () => $pa($e)->decline($p, ['update_ids' => [E::WIN_UPDATE], 'client_id' => $e->client($t)]);
                }),
            $mk('decideApproval', [A::PATCH_MANAGE], 'every client of the frozen request must be inside the caller\'s scope BEFORE the decision closure runs', true,
                function (E $e, string $t, RmmPrincipal $p) use ($pa) {
                    $id = $e->get($t, 'approval_patch');
                    $row = $e->h->module->approvals()->get($id) ?? ['approval_id' => $id, 'summary' => '', 'requested_by' => 21, 'kind' => 'patch'];
                    $req = $e->h->module->approvals()->requestOf($id) ?? [];
                    $decide = $t === E::OWN ? fn (): array => $e->h->module->approvals()->decide($id, $p->userId, true, '')
                        : static function (): array {
                            throw new \LogicException('the decision closure must not run for a request of another client');
                        };

                    return fn () => $pa($e)->decideApproval($p, $row, $req, true, $decide);
                }),
            $mk('compliance', [A::PATCH_VIEW], 'a client filter outside visibleClientIds() is 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $pa($e)->compliance($p, ['client_id' => $e->client($t)])),
            $mk('fleetUpdates', [A::PATCH_VIEW], 'a client filter outside visibleClientIds() is 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $pa($e)->fleetUpdates($p, 'available', $e->client($t), 10, 0)),
            $mk('savePolicy', [A::PATCH_MANAGE], 'writeGate(): a patch policy is fleet-wide, needs a caller not limited to some clients', true,
                function (E $e, string $t, RmmPrincipal $p) use ($pa) {
                    $k = $t === E::MISSING ? E::NO_ID : $e->patchPolicyId;

                    return fn () => $pa($e)->savePolicy($p, $t === E::OWN ? null : $k, ['name' => 'P' . random_int(1, 1 << 30)]);
                }, $fleet),
            $mk('deletePolicy', [A::PATCH_MANAGE], 'writeGate()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($pa) {
                    $id = $t === E::OWN ? (int) $pa($e)->savePolicy($e->admin, null, ['name' => 'D' . random_int(1, 1 << 30)])->data['policy']['policy_id'] : ($t === E::MISSING ? E::NO_ID : $e->patchPolicyId);

                    return fn () => $pa($e)->deletePolicy($p, $id);
                }, $fleet),
            $mk('assignPolicy', [A::PATCH_MANAGE], 'writeGate()', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $pa($e)->assignPolicy($p, $t === E::MISSING ? E::NO_ID : $e->patchPolicyId, ['scope_type' => 'client', 'scope_id' => $e->client($t), 'priority' => random_int(1, 90)]), $fleet),
            $mk('unassignPolicy', [A::PATCH_MANAGE], 'writeGate()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($pa) {
                    $id = $t === E::MISSING ? E::NO_ID : (int) $pa($e)->assignPolicy($e->admin, $e->patchPolicyId, ['scope_type' => 'device', 'scope_id' => $e->devB, 'priority' => random_int(1, 90)])->data['assignment']['assignment_id'];

                    return fn () => $pa($e)->unassignPolicy($p, $e->patchPolicyId, $id);
                }, $fleet),
            $mk('saveRing', [A::PATCH_MANAGE], 'writeGate()', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $pa($e)->saveRing($p, null, ['name' => 'ring' . random_int(1, 1 << 20), 'delay_days' => 3]), $fleet),
            $mk('deleteRing', [A::PATCH_MANAGE], 'writeGate()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($pa) {
                    $id = $t === E::MISSING ? E::NO_ID : (int) $pa($e)->saveRing($e->admin, null, ['name' => 'r' . random_int(1, 1 << 20), 'delay_days' => 3])->data['ring']['ring_id'];

                    return fn () => $pa($e)->deleteRing($p, $id);
                }, $fleet),
            $mk('saveRule#update', [A::PATCH_MANAGE], 'a rule of a client outside the caller\'s clients is 404 (the rule\'s client is read first)', true,
                function (E $e, string $t, RmmPrincipal $p) use ($pa) {
                    $r = $e->get($t, 'patch_rule');

                    return fn () => $pa($e)->saveRule($p, $r, ['name' => 'renamed', 'after_days' => 2, 'client_id' => $e->clientA()]);
                }, ['refuse' => [403, 404]]),
            $mk('saveRule#create', [A::PATCH_MANAGE], 'a rule for another client or for every client is 403', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $pa($e)->saveRule($p, null, ['name' => 'n' . random_int(1, 1 << 20), 'after_days' => 2, 'client_id' => $e->client($t)])),
            $mk('deleteRule', [A::PATCH_MANAGE], 'the rule\'s client is checked first: 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($pa) {
                    $r = $e->get($t, 'patch_rule');

                    return fn () => $pa($e)->deleteRule($p, $r);
                }),
            self::row('PatchApprovalService::select', [A::PATCH_VIEW], 'the visible clients bound the SQL: nothing of another client is selected', false,
                function (E $e, string $t, RmmPrincipal $p) {
                    $e->resetPatches();

                    return fn () => $e->h->module->patchApprovals()->select(['update_ids' => [E::WIN_UPDATE], 'device_id' => $e->dev($t)], [$e->clientA()]) === []
                        ? ActionResult::fail(404, 'not_found', 'No such update.') : ActionResult::ok('ok');
                }),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function alertingRows(): array
    {
        $mk = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('AlertingActions::' . $m, $ab, $where, $w, $f, $o);
        $aa = static fn (E $e) => $e->h->module->alertingActions();
        $win = static fn (string $at) => static fn (E $e, string $t, RmmPrincipal $p) => fn () => $aa($e)->createWindow($p, ['name' => 'N' . random_int(1, 1 << 30), 'kind' => 'once', 'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            'ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200), 'scope_type' => $at, 'scope_id' => $at === 'client' ? $e->client($t) : $e->dev($t)]);

        return [
            $mk('listAlerts', [A::DEVICE_VIEW], 'alerts->list() is bounded by visibleClientIds(): a filter on another client\'s device returns nothing', false,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $aa($e)->listAlerts($p, ['device_id' => $e->dev($t)], 50, 0), ['list' => true]),
            $mk('getAlert', [A::DEVICE_VIEW], 'alerts->find($id, visibleClientIds()) -> 404', false,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $a = $e->get($t, 'alert');

                    return fn () => $aa($e)->getAlert($p, $a);
                }),
            $mk('acknowledge', [A::ALERT_MANAGE], 'manageable(): find(id, visibleClientIds()) -> 404, then alert.manage for the client', true,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $a = $e->get($t, 'alert');

                    return fn () => $aa($e)->acknowledge($p, $a);
                }),
            $mk('resolve', [A::ALERT_MANAGE], 'manageable()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $a = $e->get($t, 'alert');

                    return fn () => $aa($e)->resolve($p, $a);
                }),
            $mk('getWindow#client', [A::DEVICE_VIEW], 'scopeVisible(): a client scope outside the caller\'s clients is 404', false,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $w = $e->get($t, 'window_client');

                    return fn () => $aa($e)->getWindow($p, $w);
                }),
            $mk('getWindow#device', [A::DEVICE_VIEW], 'scopeVisible(): a device scope whose client is outside the caller\'s clients is 404', false,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $w = $e->get($t, 'window_device');

                    return fn () => $aa($e)->getWindow($p, $w);
                }),
            $mk('createWindow#client', [A::ALERT_MANAGE], 'windowScopeAllowed(): alert.manage for that client (incl. clientOk -> 403)', true, $win('client')),
            $mk('createWindow#device', [A::ALERT_MANAGE], 'windowScopeAllowed(): the device must be inside the caller\'s clients (404)', true, $win('device')),
            $mk('createWindow#all', [A::ADMIN], 'windowScopeAllowed(): a window on every client (all, site, group, tag) needs a caller not limited to some clients (TEN-4)', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $aa($e)->createWindow($p, ['name' => 'All' . random_int(1, 1 << 30), 'kind' => 'once', 'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
                    'ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200), 'scope_type' => 'all']), ['ctl' => 'admin']),
            $mk('updateWindow', [A::ALERT_MANAGE], 'scopeVisible() (404) then windowScopeAllowed() for the old AND the new scope', true,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $w = $e->get($t, 'window_client');

                    return fn () => $aa($e)->updateWindow($p, $w, ['name' => 'Renamed']);
                }),
            $mk('updateWindow#rescope', [A::ALERT_MANAGE], 'the NEW scope is checked too: an own window cannot be moved onto another client', true,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $w = $e->get('own', 'window_client');

                    return fn () => $aa($e)->updateWindow($p, $w, ['scope_type' => 'client', 'scope_id' => $e->client($t)]);
                }),
            $mk('deleteWindow', [A::ALERT_MANAGE], 'scopeVisible() (404) then windowScopeAllowed()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $w = $e->get($t, 'window_client');

                    return fn () => $aa($e)->deleteWindow($p, $w);
                }),
            $mk('deviceMaintenance', [A::DEVICE_VIEW], 'device(): DEVICE_VIEW for the client -> 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $aa($e)->deviceMaintenance($p, $e->dev($t))),
            $mk('deviceAlerting', [A::DEVICE_VIEW], 'device()', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $aa($e)->deviceAlerting($p, $e->dev($t))),
            $mk('getPolicy', [A::DEVICE_VIEW], 'scopeVisible(): an escalation policy of a client outside the caller\'s clients is 404', false,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $k = $e->get($t, 'esc_client');

                    return fn () => $aa($e)->getPolicy($p, $k);
                }),
            $mk('createPolicy#all', [A::ADMIN], 'scopeWriteRefusal(): a policy on every client (all, site, group, tag) needs a caller not limited to some clients (TEN-4)', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $aa($e)->createPolicy($p, ['name' => 'N' . random_int(1, 1 << 30), 'scope_type' => 'all',
                    'steps' => [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => '1']]]]]), ['ctl' => 'admin']),
            $mk('createPolicy#scope', [A::ADMIN], 'scopeWriteRefusal(): a client or device scope must be inside the caller\'s clients (404) (TEN-4)', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $aa($e)->createPolicy($p, ['name' => 'N' . random_int(1, 1 << 30), 'scope_type' => 'client', 'scope_id' => $e->client($t),
                    'steps' => [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => '1']]]]])),
            $mk('updatePolicy', [A::ADMIN], 'scopeVisible() on the policy\'s scope -> 404, then scopeWriteRefusal() on the old scope', true,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $k = $e->get($t, 'esc_client');

                    return fn () => $aa($e)->updatePolicy($p, $k, ['name' => 'Renamed' . random_int(1, 1 << 30)]);
                }),
            $mk('updatePolicy#rescope', [A::ADMIN], 'the NEW scope is checked too: an own policy cannot be moved onto another client', true,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $k = $e->get('own', 'esc_client');

                    return fn () => $aa($e)->updatePolicy($p, $k, ['scope_type' => 'client', 'scope_id' => $e->client($t)]);
                }),
            $mk('deletePolicy', [A::ADMIN], 'scopeVisible() on the policy\'s scope -> 404, then scopeWriteRefusal()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $k = $e->get($t, 'esc_client');

                    return fn () => $aa($e)->deletePolicy($p, $k);
                }),
            $mk('getParent', [A::DEVICE_VIEW], 'device(): 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $aa($e)->getParent($p, $e->dev($t))),
            $mk('setParent#child', [A::ALERT_MANAGE], 'device(alert.manage): 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $parent = $e->get('own', 'device');

                    return fn () => $aa($e)->setParent($p, $e->dev($t), $parent);
                }),
            $mk('setParent#parent', [A::ALERT_MANAGE], 'the PARENT device is looked up with the same scope: 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $child = $e->get('own', 'device');

                    return fn () => $aa($e)->setParent($p, $child, $e->dev($t));
                }),
            $mk('clearParent', [A::ALERT_MANAGE], 'device(alert.manage): 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($aa) {
                    $d = $e->dev($t);
                    if ($t === E::OWN) {
                        $d = $e->get('own', 'device');
                        $aa($e)->setParent($e->admin, $d, $e->devA);
                    }

                    return fn () => $aa($e)->clearParent($p, $d);
                }, ['refuse' => [404]]),
            $mk('getThresholds', [A::DEVICE_VIEW], 'device(): 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $aa($e)->getThresholds($p, $e->dev($t), E::CHECK_KEY)),
            $mk('setThresholds', [A::DEVICE_MANAGE], 'device(device.manage): 404', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $aa($e)->setThresholds($p, $e->dev($t), E::CHECK_KEY, ['warn' => ['op' => 'gt', 'value' => 70]])),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function logChangeHypervisorRows(): array
    {
        $lg = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('LogActions::' . $m, $ab, $where, $w, $f, $o);
        $ch = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('ChangeActions::' . $m, $ab, $where, $w, $f, $o);
        $hy = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('HypervisorActions::' . $m, $ab, $where, $w, $f, $o);
        $la = static fn (E $e) => $e->h->module->logActions();
        $ca = static fn (E $e) => $e->h->module->changeActions();
        $ha = static fn (E $e) => $e->h->module->hypervisorActions();

        return [
            $lg('deviceLogs', [A::JOB_RUN_SAVED], 'device(): clientOk + the grant for the client -> 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $la($e)->deviceLogs($p, $e->dev($t), [], 10, 0)),
            $lg('fleetLogs#device', [A::JOB_RUN_SAVED], 'a device filter goes through device() (404)', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $la($e)->fleetLogs($p, ['device_id' => $e->dev($t)], 10, 0)),
            $lg('fleetLogs#client', [A::JOB_RUN_SAVED], 'a client filter outside the caller\'s clients is 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $la($e)->fleetLogs($p, ['client_id' => $e->client($t)], 10, 0)),
            $lg('stats', [A::JOB_RUN_SAVED], 'a device goes through device() (404)', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $la($e)->stats($p, $e->dev($t))),
            $lg('createRule', [A::DEVICE_MANAGE], 'manage(client): clientOk', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $la($e)->createRule($p, ['name' => 'N' . random_int(1, 1 << 30), 'min_level' => 'error', 'contains' => 'spooler', 'severity' => 'fail', 'client_id' => $e->client($t)])),
            $lg('updateRule', [A::DEVICE_MANAGE], 'visibleRule(): a rule of another client is 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($la) {
                    $r = $e->get($t, 'log_rule');

                    return fn () => $la($e)->updateRule($p, $r, ['name' => 'Renamed' . random_int(1, 1 << 30)]);
                }),
            $lg('updateRule#rescope', [A::DEVICE_MANAGE], 'a NEW client_id is checked with manage() too', true,
                function (E $e, string $t, RmmPrincipal $p) use ($la) {
                    $r = $e->get('own', 'log_rule');

                    return fn () => $la($e)->updateRule($p, $r, ['client_id' => $e->client($t)]);
                }),
            $lg('deleteRule', [A::DEVICE_MANAGE], 'visibleRule(): 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($la) {
                    $r = $e->get($t, 'log_rule');

                    return fn () => $la($e)->deleteRule($p, $r);
                }),
            $lg('getRule', [A::DEVICE_VIEW], 'visibleRule(): 404', false,
                function (E $e, string $t, RmmPrincipal $p) use ($la) {
                    $r = $e->get($t, 'log_rule');

                    return fn () => $la($e)->getRule($p, $r);
                }),
            $ch('deviceChanges', [A::CHANGE_VIEW], 'device(): clientOk + the grant for the client -> 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ca($e)->deviceChanges($p, $e->dev($t), [], 10, 0)),
            $ch('fleetChanges#device', [A::CHANGE_VIEW], 'a device filter goes through device() (404)', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ca($e)->fleetChanges($p, ['device_id' => $e->dev($t)], 10, 0)),
            $ch('fleetChanges#client', [A::CHANGE_VIEW], 'a client filter outside the caller\'s clients is 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ca($e)->fleetChanges($p, ['client_id' => $e->client($t)], 10, 0)),
            $ch('timeline', [A::CHANGE_VIEW], 'device()', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ca($e)->timeline($p, $e->dev($t), [], 10)),
            $ch('deviceStatus', [A::CHANGE_VIEW], 'device()', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ca($e)->deviceStatus($p, $e->dev($t))),
            $ch('getRule', [A::CHANGE_VIEW], 'the rule of another client is 404', false,
                function (E $e, string $t, RmmPrincipal $p) use ($ca) {
                    $r = $e->get($t, 'change_rule');

                    return fn () => $ca($e)->getRule($p, $r);
                }),
            $ch('createRule', [A::CHANGE_MANAGE], 'client_id is checked with the grant for that client', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $ca($e)->createRule($p, ['name' => 'N' . random_int(1, 1 << 30), 'watch' => 'win.startup', 'severity' => 'fail', 'client_id' => $e->client($t)])),
            $ch('updateRule', [A::CHANGE_MANAGE], 'the rule of another client is 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ca) {
                    $r = $e->get($t, 'change_rule');

                    return fn () => $ca($e)->updateRule($p, $r, ['name' => 'Renamed' . random_int(1, 1 << 30)]);
                }),
            $ch('updateRule#rescope', [A::CHANGE_MANAGE], 'a NEW client_id is checked too', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ca) {
                    $r = $e->get('own', 'change_rule');

                    return fn () => $ca($e)->updateRule($p, $r, ['client_id' => $e->client($t)]);
                }),
            $ch('deleteRule', [A::CHANGE_MANAGE], 'the rule of another client is 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ca) {
                    $r = $e->get($t, 'change_rule');

                    return fn () => $ca($e)->deleteRule($p, $r);
                }),
            $hy('hosts', [A::HYPERVISOR_VIEW], 'a client filter outside the caller\'s clients is 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ha($e)->hosts($p, ['client_id' => $e->client($t)], 10, 0)),
            $hy('host', [A::HYPERVISOR_VIEW], 'clientOk + the grant for the device\'s client -> 404', false,
                function (E $e, string $t, RmmPrincipal $p) use ($ha) {
                    $e->newVm($e->devA);   // makes device A a reporting hypervisor host

                    return fn () => $ha($e)->host($p, $e->dev($t), [], 10, 0);
                }),
            $hy('vms', [A::HYPERVISOR_VIEW], 'a client filter outside the caller\'s clients is 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ha($e)->vms($p, ['client_id' => $e->client($t)], 10, 0)),
            $hy('vm', [A::HYPERVISOR_VIEW], 'reader->vm($id, visible clients) -> 404', false,
                function (E $e, string $t, RmmPrincipal $p) use ($ha) {
                    $v = $e->get($t, 'vm');

                    return fn () => $ha($e)->vm($p, $v);
                }),
            $hy('suggestAssets', [A::HYPERVISOR_VIEW], 'reader->vm($id, visible clients) -> 404', false,
                function (E $e, string $t, RmmPrincipal $p) use ($ha) {
                    $v = $e->get($t, 'vm');

                    return fn () => $ha($e)->suggestAssets($p, $v);
                }),
            $hy('setVmIgnored', [A::HYPERVISOR_MANAGE], 'reader->vm($id, visible clients) -> 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ha) {
                    $v = $e->get($t, 'vm');

                    return fn () => $ha($e)->setVmIgnored($p, $v, true, 'matrix');
                }),
            $hy('setHostIgnored', [A::HYPERVISOR_MANAGE], 'clientOk + the grant for the device\'s client -> 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ha) {
                    $d = $e->dev($t);
                    if ($t === E::OWN) {
                        $e->newVm($d);
                    }

                    return fn () => $ha($e)->setHostIgnored($p, $d, true);
                }),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function inventoryRows(): array
    {
        $iv = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('InventoryActions::' . $m, $ab, $where, $w, $f, $o);
        $ia = static fn (E $e) => $e->h->module->inventory();
        $fa = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('FieldActions::' . $m, $ab, $where, $w, $f, $o);
        $pa = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('PolicyActions::' . $m, $ab, $where, $w, $f, $o);
        $ai = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('AutoInstallActions::' . $m, $ab, $where, $w, $f, $o);
        $fleet = ['ctl' => 'admin'];

        return [
            $iv('tagDevice', [A::DEVICE_MANAGE], 'device(): clientOk + DEVICE_VIEW(client) -> 404', true, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ia($e)->tagDevice($p, $e->dev($t), $e->tagId)),
            $iv('untagDevice', [A::DEVICE_MANAGE], 'device() -> 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ia) {
                    $d = $e->dev($t);
                    $ia($e)->tagDevice($e->admin, $d === E::NO_ID ? $e->devA : $d, $e->tagId);

                    return fn () => $ia($e)->untagDevice($p, $d, $e->tagId);
                }),
            $iv('updateTag', [A::DEVICE_MANAGE], 'fleetWide(): tags are shared by every client', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ia) {
                    $k = $e->get($t, 'tag');

                    return fn () => $ia($e)->updateTag($p, $k, ['color' => '#112233']);
                }, $fleet),
            $iv('deleteTag', [A::DEVICE_MANAGE], 'fleetWide()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ia) {
                    $k = $e->get($t, 'tag');

                    return fn () => $ia($e)->deleteTag($p, $k);
                }, $fleet),
            $iv('updateGroup', [A::DEVICE_MANAGE], 'fleetWide(): groups are shared by every client', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ia) {
                    $k = $e->get($t, 'group');

                    return fn () => $ia($e)->updateGroup($p, $k, ['description' => 'matrix']);
                }, $fleet),
            $iv('deleteGroup', [A::DEVICE_MANAGE], 'fleetWide()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ia) {
                    $k = $e->get($t, 'group');

                    return fn () => $ia($e)->deleteGroup($p, $k);
                }, $fleet),
            $iv('addGroupDevices', [A::DEVICE_MANAGE], 'every device is checked with device(): one outside the scope refuses the whole call (404)', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $ia($e)->addGroupDevices($p, $e->groupId, [$e->dev($t)])),
            $iv('removeGroupDevice', [A::DEVICE_MANAGE], 'device() -> 404', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ia) {
                    $d = $e->dev($t);
                    if ($t === E::OWN) {
                        $ia($e)->addGroupDevices($e->admin, $e->groupId, [$d]);
                    }

                    return fn () => $ia($e)->removeGroupDevice($p, $e->groupId, $d);
                }),
            $iv('setGroupTags', [A::DEVICE_MANAGE], 'fleetWide()', true,
                function (E $e, string $t, RmmPrincipal $p) use ($ia) {
                    $k = $e->get($t, 'group');

                    return fn () => $ia($e)->setGroupTags($p, $k, [$e->tagId]);
                }, $fleet),
            $iv('refreshSoftware', [A::JOB_RUN_SAVED], 'device() -> 404', true, fn (E $e, string $t, RmmPrincipal $p) => fn () => $ia($e)->refreshSoftware($p, $e->dev($t))),
            $fa('change', [A::ADMIN], 'a custom field definition is fleet-wide (no client owns it): a caller limited to some clients may not change it', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $k = $e->get($t, 'field');

                    return fn () => $e->h->module->fieldActions()->change($p, $k, ['label' => 'Changed']);
                }, $fleet),
            $fa('setValue#device', [A::DEVICE_MANAGE], 'a device-scope value: the device must be inside the caller\'s clients (404)', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $e->h->module->fieldActions()->setValue($p, $e->fieldDevice, $e->dev($t), 'matrix')),
            $fa('setValue#client', [A::DEVICE_MANAGE], 'a client-scope value: clientOk -> 404', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $e->h->module->fieldActions()->setValue($p, $e->fieldClient, $e->client($t), 'matrix')),
            $fa('setValue#site', [A::DEVICE_MANAGE], 'a site-scope value: the client the site belongs to must be inside the caller\'s clients', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $f = (int) $e->h->module->fieldActions()->define($e->admin, ['name' => 'site' . random_int(1, 1 << 20), 'scope' => 'site', 'type' => 'text'])->data['field']['field_id'];

                    return fn () => $e->h->module->fieldActions()->setValue($p, $f, $e->location($t), 'matrix', $e->client($t));
                }, ['refuse' => [403, 404, 422]]),
            $fa('delete', [A::ADMIN], 'a custom field definition is fleet-wide', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $k = $e->get($t, 'field');

                    return fn () => $e->h->module->fieldActions()->delete($p, $k);
                }, $fleet),
            $pa('update', [A::ADMIN], 'a script policy is fleet-wide: a caller limited to some clients may not change it', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $k = $e->get($t, 'policy');

                    return fn () => $e->h->module->policyActions()->update($p, $k, ['enabled' => false]);
                }, $fleet),
            $pa('delete', [A::ADMIN], 'a script policy is fleet-wide', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $k = $e->get($t, 'policy');

                    return fn () => $e->h->module->policyActions()->delete($p, $k);
                }, $fleet),
            $pa('assign#client', [A::ADMIN], 'an assignment to a client outside the caller\'s clients is refused', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $e->h->module->policyActions()->assign($p, $e->policyId, ['scope_type' => 'client', 'scope_id' => $e->client($t), 'priority' => random_int(200, 900000)])),
            $pa('assign#device', [A::ADMIN], 'an assignment to a device outside the caller\'s clients is refused', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $e->h->module->policyActions()->assign($p, $e->policyId, ['scope_type' => 'device', 'scope_id' => $e->dev($t), 'priority' => random_int(200, 900000)])),
            $pa('assign#global', [A::ADMIN], 'a fleet-wide assignment (global scope) needs a caller not limited to some clients', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $e->h->module->policyActions()->assign($p, $e->policyId, ['scope_type' => 'global', 'priority' => random_int(200, 900000)]), $fleet),
            $pa('unassign#client', [A::ADMIN], 'the assignment of another client is 404', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $k = $e->get($t, 'pol_assign_client');

                    return fn () => $e->h->module->policyActions()->unassign($p, $k);
                }),
            $pa('unassign#device', [A::ADMIN], 'the assignment of a device of another client is 404', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $k = $e->get($t, 'pol_assign_device');

                    return fn () => $e->h->module->policyActions()->unassign($p, $k);
                }),
            $ai('setAuto', [A::SOFTWARE_DEPLOY], 'unrestricted(): a package that installs on new devices of every client', true,
                function (E $e, string $t, RmmPrincipal $p) {
                    $k = $e->get($t, 'package');

                    return fn () => $e->h->module->autoInstallActions()->setAuto($p, $k, ['scope' => 'all']);
                }, $fleet),
            $ai('selectable', [A::SOFTWARE_VIEW], 'clientOk -> 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $e->h->module->autoInstallActions()->selectable($p, $e->client($t), 0, 'windows')),
            $ai('mayTick', [A::SOFTWARE_DEPLOY], 'SOFTWARE_DEPLOY(client) incl. clientOk', false,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => self::refusal($e->h->module->autoInstallActions()->mayTick($p, $e->client($t)))),
            $ai('setParam#client', [A::SOFTWARE_MANAGE], 'a client parameter needs that client: clientOk -> 404', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $e->h->module->autoInstallActions()->setParam($p, $e->packageId, ['name' => 'LICENSE', 'value' => 'x', 'scope' => 'client', 'scope_id' => $e->client($t)]),
                ['control' => [200, 422]]),
            $ai('clearParam#client', [A::SOFTWARE_MANAGE], 'a client parameter needs that client: clientOk -> 404', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $e->h->module->autoInstallActions()->clearParam($p, $e->packageId, ['name' => 'LICENSE', 'scope' => 'client', 'scope_id' => $e->client($t)]),
                ['control' => [200, 404, 422]]),
            $ai('deleteFile', [A::SOFTWARE_MANAGE], 'unrestricted(): hosted installers are shared by every client', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $e->h->module->autoInstallActions()->deleteFile($p, E::NO_ID), $fleet + ['control' => [404]]),
            $ai('deviceApps', [A::SOFTWARE_VIEW], 'clientOk + DEVICE_VIEW + SOFTWARE_VIEW for the client -> 404', false, fn (E $e, string $t, RmmPrincipal $p) => fn () => $e->h->module->autoInstallActions()->deviceApps($p, $e->dev($t))),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function adminRows(): array
    {
        $ad = static fn (string $m, array $ab, string $where, bool $w, \Closure $f, array $o = []): array => self::row('RmmAdmin::' . $m, $ab, $where, $w, $f, $o);
        $am = static fn (E $e) => $e->h->module->admin();

        return [
            $ad('clearUpdateFailures', [A::BINARY_PUBLISH], 'a named device must be inside the caller\'s clients (404)', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $am($e)->clearUpdateFailures($p, $e->dev($t)), ['control' => [200]]),
            $ad('clearUpdateFailures#all', [A::BINARY_PUBLISH], 'with no device the call reaches every client: needs a caller not limited to some clients', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $am($e)->clearUpdateFailures($p, null), ['ctl' => 'admin']),
            $ad('downloadInstaller', [A::TOKEN_MANAGE], 'deny(TOKEN_MANAGE, client) incl. clientOk -> 403, before any token is minted', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $am($e)->downloadInstaller($p, $e->client($t), 0, 'stable', 24, 5, 'matrix', 'amd64')),
            $ad('deploymentCommands', [A::TOKEN_MANAGE], 'deny(TOKEN_MANAGE, client) incl. clientOk -> 403, before any token is minted', true,
                fn (E $e, string $t, RmmPrincipal $p) => fn () => $am($e)->deploymentCommands($p, $e->client($t), 0, 'stable', 24, 5, 'matrix', 'amd64')),
        ];
    }

    /**
     * The REST routes that carry an id (path segments after `endpoint_devices`). A token in braces is replaced per target: {d} the device, {dn} the same in a
     * body, {client}, {job}, {tooljob}, {tid}, {alert}, {appr}, {ad}/{ac} package assignment of a device/a client, {win}/{wind} maintenance window of a client/a device,
     * {esc}, {lr}, {cr}, {pac}/{pad} script policy assignment of a client/a device, {sched}, {vm}, {pkg}, {script}, {policy}, {field}, {group}, {prule} ...
     *
     * @return list<array<string,mixed>>
     */
    private static function restRows(): array
    {
        $routes = [
            // [api class, method, path, body, abilities, control statuses (null = 2xx), note, options]
            ['TechnicianApi', 'GET', '{d}', null, [A::DEVICE_VIEW]],
            ['TechnicianApi', 'GET', '{d}/jobs', null, [A::DEVICE_VIEW]],
            ['TechnicianApi', 'POST', '{d}/jobs', ['type' => 'powershell', 'script' => 'Get-Date'], [A::JOB_RUN_SCRIPT]],
            ['TechnicianApi', 'POST', '{d}/jobs/{job}/cancel', null, [A::JOB_RUN_SAVED]],
            ['TechnicianApi', 'POST', '{d}/remote', [], [A::REMOTE_LAUNCH]],
            ['TechnicianApi', 'POST', '{d}/remote', ['view' => 'terminal'], [A::REMOTE_LAUNCH, A::REMOTE_MANAGE], null, 'terminal'],
            ['TechnicianApi', 'GET', '{d}/live', null, [A::DEVICE_VIEW]],
            ['TechnicianApi', 'GET', '{d}/network', null, [A::DEVICE_VIEW]],
            ['TechnicianApi', 'GET', '{d}/policy', null, [A::DEVICE_VIEW]],
            ['TechnicianApi', 'GET', '{d}/fields', null, [A::DEVICE_VIEW]],
            ['TechnicianApi', 'PUT', '{d}/fields/{fieldid}', ['value' => 'x'], [A::DEVICE_MANAGE]],
            ['TechnicianApi', 'GET', '{d}/software', null, [A::DEVICE_VIEW]],
            ['TechnicianApi', 'POST', '{d}/software/refresh', null, [A::JOB_RUN_SAVED], [200, 202]],
            ['TechnicianApi', 'GET', '{d}/tags', null, [A::DEVICE_VIEW]],
            ['TechnicianApi', 'POST', '{d}/tags', ['tag' => 'matrix-tag'], [A::DEVICE_MANAGE]],
            ['TechnicianApi', 'GET', '{d}/checks/' . E::CHECK_KEY . '/history', null, [A::DEVICE_VIEW]],
            ['TechnicianApi', 'GET', 'policies/{policy}', null, [A::DEVICE_VIEW], null, 'fleet-wide read', ['public' => true]],
            ['TechnicianApi', 'PUT', 'policies/{policy}', ['enabled' => false], [A::ADMIN], null, 'fleet-wide', ['ctl' => 'admin']],
            ['TechnicianApi', 'DELETE', 'policies/{policy}', null, [A::ADMIN], null, 'fleet-wide', ['ctl' => 'admin']],
            ['TechnicianApi', 'POST', 'policies/{policyfix}/assignments', ['scope_type' => 'client', 'scope_id' => '{client}', 'priority' => 777], [A::ADMIN], null, 'client scope'],
            ['TechnicianApi', 'DELETE', 'policies/{policyfix}/assignments/{pac}', null, [A::ADMIN], null, 'client assignment'],
            ['TechnicianApi', 'DELETE', 'policies/{policyfix}/assignments/{pad}', null, [A::ADMIN], null, 'device assignment'],
            ['TechnicianApi', 'GET', 'scripts/{script}', null, [A::DEVICE_VIEW], null, 'fleet-wide read', ['public' => true]],
            ['TechnicianApi', 'PUT', 'scripts/{script}', ['description' => 'x'], [A::JOB_RUN_SCRIPT], null, 'fleet-wide', ['ctl' => 'admin']],
            ['TechnicianApi', 'DELETE', 'scripts/{script}', null, [A::JOB_RUN_SCRIPT], null, 'fleet-wide', ['ctl' => 'admin']],
            ['TechnicianApi', 'GET', 'scripts/{script}/runs', null, [A::JOB_RUN_SAVED], null, 'runs filtered to the caller\'s clients', ['public' => true]],
            ['TechnicianApi', 'GET', 'approvals/{appr}', null, [A::DEVICE_VIEW]],
            ['TechnicianApi', 'POST', 'approvals/{appr}/approve', [], [A::JOB_APPROVE]],
            ['TechnicianApi', 'POST', 'approvals/{appr}/reject', [], [A::JOB_APPROVE]],
            ['TechnicianApi', 'POST', 'approvals/{appr}/cancel', [], [A::JOB_APPROVE]],
            ['TechnicianApi', 'GET', 'schedules/{sched}', null, [A::DEVICE_VIEW]],
            ['TechnicianApi', 'PUT', 'schedules/{sched}', ['interval_s' => 7200], [A::ADMIN]],
            ['TechnicianApi', 'DELETE', 'schedules/{sched}', null, [A::ADMIN]],
            ['TechnicianApi', 'GET', 'schedules/{sched}/history?device_id={d}', null, [A::DEVICE_VIEW]],
            ['TechnicianApi', 'PUT', 'fields/{fieldid}/values/{d}', ['value' => 'x'], [A::DEVICE_MANAGE]],
            ['TechnicianApi', 'POST', 'runs', ['library_script_id' => '{scriptid}', 'target' => ['type' => 'device', 'id' => '{dn}']], [A::JOB_RUN_SAVED], null, 'device target', ['refuse' => [403, 404, 422]]],
            ['TechnicianApi', 'POST', 'groups/{groupfix}/devices', ['device_ids' => ['{dn}']], [A::DEVICE_MANAGE]],
            ['TechnicianApi', 'DELETE', 'groups/{groupfix}/devices/{dgrp}', null, [A::DEVICE_MANAGE]],
            ['AlertingApi', 'GET', '{d}/alerts', null, [A::DEVICE_VIEW]],
            ['AlertingApi', 'GET', '{d}/alerting', null, [A::DEVICE_VIEW]],
            ['AlertingApi', 'GET', '{d}/maintenance', null, [A::DEVICE_VIEW]],
            ['AlertingApi', 'GET', '{d}/parent', null, [A::DEVICE_VIEW]],
            ['AlertingApi', 'PUT', '{dq}/parent', ['parent_device_id' => '{dA}'], [A::ALERT_MANAGE], null, 'child'],
            ['AlertingApi', 'PUT', '{dfresh}/parent', ['parent_device_id' => '{dn}'], [A::ALERT_MANAGE], null, 'parent of another client'],
            ['AlertingApi', 'DELETE', '{d}/parent', null, [A::ALERT_MANAGE], [200, 404], '', ['w' => false]],
            ['AlertingApi', 'GET', '{d}/checks/' . E::CHECK_KEY . '/thresholds', null, [A::DEVICE_VIEW]],
            ['AlertingApi', 'PUT', '{d}/checks/' . E::CHECK_KEY . '/thresholds', ['warn' => ['op' => 'gt', 'value' => 70]], [A::DEVICE_MANAGE]],
            ['AlertingApi', 'GET', 'alerts/{alert}', null, [A::DEVICE_VIEW]],
            ['AlertingApi', 'POST', 'alerts/{alert}/ack', null, [A::ALERT_MANAGE]],
            ['AlertingApi', 'POST', 'alerts/{alert}/resolve', null, [A::ALERT_MANAGE]],
            ['AlertingApi', 'GET', 'alerts?device_id={d}', null, [A::DEVICE_VIEW], null, 'filter', ['list' => true]],
            ['AlertingApi', 'GET', 'maintenance/{win}', null, [A::DEVICE_VIEW]],
            ['AlertingApi', 'PATCH', 'maintenance/{win}', ['name' => 'Renamed'], [A::ALERT_MANAGE]],
            ['AlertingApi', 'DELETE', 'maintenance/{win}', null, [A::ALERT_MANAGE]],
            ['AlertingApi', 'GET', 'maintenance/{wind}', null, [A::DEVICE_VIEW], null, 'device-scope window'],
            ['AlertingApi', 'GET', 'escalation_policies/{esc}', null, [A::DEVICE_VIEW]],
            ['AlertingApi', 'PATCH', 'escalation_policies/{esc}', ['name' => 'Renamed2'], [A::ADMIN]],
            ['AlertingApi', 'DELETE', 'escalation_policies/{esc}', null, [A::ADMIN]],
            ['RemoteApi', 'GET', '{d}/services', null, [A::REMOTE_MANAGE]],
            ['RemoteApi', 'POST', '{d}/services/query', [], [A::REMOTE_MANAGE]],
            ['RemoteApi', 'POST', '{d}/services/Spooler/start', [], [A::REMOTE_MANAGE]],
            ['RemoteApi', 'GET', '{d}/processes', null, [A::REMOTE_MANAGE]],
            ['RemoteApi', 'POST', '{d}/processes/query', [], [A::REMOTE_MANAGE]],
            ['RemoteApi', 'POST', '{d}/processes/4242/kill', ['name' => 'notepad.exe', 'confirm' => true], [A::REMOTE_MANAGE]],
            ['RemoteApi', 'GET', '{d}/remote/mesh', null, [A::REMOTE_LAUNCH]],
            ['RemoteApi', 'GET', '{d}/remote/jobs/{tooljob}', null, [A::REMOTE_MANAGE]],
            ['RemoteApi', 'PUT', '{d}/remote/node', ['node_id' => 'node//' . str_repeat('C', 24) . '7'], [A::DEVICE_MANAGE]],
            ['RemoteApi', 'POST', '{dq}/mesh/install', ['force' => true], [A::REMOTE_MANAGE], null, '', ['mesh' => 'https']],
            ['RemoteApi', 'POST', '{dq}/mesh/uninstall', ['confirm' => true], [A::REMOTE_MANAGE]],
            ['RemoteApi', 'GET', '{d}/files', null, [A::REMOTE_MANAGE]],
            ['RemoteApi', 'POST', '{d}/files/download', ['path' => 'C:\\Temp\\r.csv'], [A::REMOTE_MANAGE]],
            ['RemoteApi', 'POST', '{d}/files/upload', ['path' => 'C:\\Temp\\u.txt', 'size' => 5, 'sha256' => hash('sha256', 'hello'), 'confirm' => true], [A::ADMIN]],
            ['RemoteApi', 'GET', '{d}/files/{tid}', null, [A::REMOTE_MANAGE]],
            ['RemoteApi', 'DELETE', '{d}/files/{tid}', null, [A::REMOTE_MANAGE]],
            ['RemoteApi', 'GET', '{d}/files/{tid}/content', null, [A::REMOTE_MANAGE], [409]],
            ['RemoteApi', 'GET', '{dA}/files/{tid}/content', null, [A::REMOTE_MANAGE], [409], 'own device, transfer of another client'],
            ['RemoteApi', 'GET', '{d}/logs', null, [A::JOB_RUN_SAVED]],
            ['RemoteApi', 'GET', '{d}/logs/stats', null, [A::JOB_RUN_SAVED]],
            ['RemoteApi', 'GET', 'logs?device_id={d}', null, [A::JOB_RUN_SAVED]],
            ['RemoteApi', 'GET', 'logs?client_id={client}', null, [A::JOB_RUN_SAVED]],
            ['RemoteApi', 'GET', 'logs/rules/{lr}', null, [A::DEVICE_VIEW]],
            ['RemoteApi', 'PATCH', 'logs/rules/{lr}', ['name' => 'Renamed3'], [A::DEVICE_MANAGE]],
            ['RemoteApi', 'DELETE', 'logs/rules/{lr}', null, [A::DEVICE_MANAGE]],
            ['RemoteApi', 'POST', 'logs/rules', ['name' => 'NEW', 'min_level' => 'error', 'contains' => 'x', 'severity' => 'fail', 'client_id' => '{client}'], [A::DEVICE_MANAGE]],
            ['RemoteApi', 'POST', 'mesh/install', ['target' => ['type' => 'device', 'id' => '{dn}'], 'confirm' => true], [A::REMOTE_MANAGE], null, 'device target', ['refuse' => [403, 404, 422], 'mesh' => 'https']],
            ['PatchApi', 'GET', '{d}/patches', null, [A::PATCH_VIEW]],
                        ['PatchApi', 'GET', '{d}/patches/history', null, [A::PATCH_VIEW]],
            ['PatchApi', 'POST', '{dq}/patches/scan', null, [A::PATCH_MANAGE]],
            ['PatchApi', 'POST', '{d}/patches/install', ['mode' => 'dry_run'], [A::PATCH_MANAGE], [200, 202], '', ['w' => false]],
            ['PatchApi', 'POST', 'patches/approve', ['update_ids' => [E::WIN_UPDATE], 'device_id' => '{dn}'], [A::PATCH_MANAGE], [200, 202], '', ['reset' => true]],
            ['PatchApi', 'POST', 'patches/decline', ['update_ids' => [E::WIN_UPDATE], 'device_id' => '{dn}'], [A::PATCH_MANAGE], null, '', ['reset' => true]],
            ['PatchApi', 'GET', 'patches/compliance?client_id={client}', null, [A::PATCH_VIEW]],
            ['PatchApi', 'GET', 'patches?client_id={client}', null, [A::PATCH_VIEW]],
            ['PatchApi', 'PUT', 'patches/rules/{prule}', ['name' => 'rr', 'after_days' => 2, 'client_id' => '{ownclient}'], [A::PATCH_MANAGE]],
            ['PatchApi', 'DELETE', 'patches/rules/{prule}', null, [A::PATCH_MANAGE]],
            ['PatchApi', 'PUT', 'patch_policies/{ppolicy}', ['name' => 'pp'], [A::PATCH_MANAGE], null, 'fleet-wide', ['ctl' => 'admin']],
            ['PatchApi', 'DELETE', 'patch_policies/{ppolicy}', null, [A::PATCH_MANAGE], null, 'fleet-wide', ['ctl' => 'admin']],
            ['DeployApi', 'GET', '{d}/deployments', null, [A::SOFTWARE_VIEW]],
            ['DeployApi', 'GET', '{d}/deployments/history', null, [A::SOFTWARE_VIEW]],
            ['DeployApi', 'POST', '{d}/deployments/detect', null, [A::SOFTWARE_MANAGE], [200, 202, 409, 422], '', ['w' => false]],
            ['DeployApi', 'POST', '{dq}/deployments/run', ['package_id' => '{pkgfix}', 'mode' => 'dry_run'], [A::SOFTWARE_MANAGE], [200, 202]],
            ['DeployApi', 'DELETE', 'software_assignments/{ad}', null, [A::SOFTWARE_DEPLOY], null, 'device assignment'],
            ['DeployApi', 'DELETE', 'software_assignments/{ac}', null, [A::SOFTWARE_DEPLOY], null, 'client assignment'],
            ['DeployApi', 'POST', 'software_assignments', ['package_id' => '{pkgnew}', 'scope_type' => 'device', 'scope_id' => '{dn}'], [A::SOFTWARE_DEPLOY], null, 'device scope'],
            ['DeployApi', 'POST', 'software_assignments', ['package_id' => '{pkgnew}', 'scope_type' => 'client', 'scope_id' => '{client}'], [A::SOFTWARE_DEPLOY], null, 'client scope'],
            ['DeployApi', 'GET', 'software_report?client_id={client}', null, [A::SOFTWARE_VIEW]],
            ['DeployApi', 'PUT', 'software_packages/{pkg}', ['name' => 'Renamed4'], [A::SOFTWARE_MANAGE], null, 'fleet-wide', ['ctl' => 'admin']],
            ['DeployApi', 'DELETE', 'software_packages/{pkg}', null, [A::SOFTWARE_MANAGE], null, 'fleet-wide', ['ctl' => 'admin']],
            ['ChangeApi', 'GET', '{d}/changes', null, [A::CHANGE_VIEW]],
            ['ChangeApi', 'GET', '{d}/changes/timeline', null, [A::CHANGE_VIEW]],
            ['ChangeApi', 'GET', '{d}/changes/status', null, [A::CHANGE_VIEW]],
            ['ChangeApi', 'GET', 'changes?device_id={d}', null, [A::CHANGE_VIEW]],
            ['ChangeApi', 'GET', 'changes?client_id={client}', null, [A::CHANGE_VIEW]],
            ['ChangeApi', 'GET', 'changes/rules/{cr}', null, [A::CHANGE_VIEW]],
            ['ChangeApi', 'PATCH', 'changes/rules/{cr}', ['name' => 'Renamed5'], [A::CHANGE_MANAGE]],
            ['ChangeApi', 'DELETE', 'changes/rules/{cr}', null, [A::CHANGE_MANAGE]],
            ['HypervisorApi', 'GET', '{d}/hypervisor', null, [A::HYPERVISOR_VIEW]],
            ['HypervisorApi', 'PUT', '{d}/hypervisor', ['ignored' => true], [A::HYPERVISOR_MANAGE]],
            ['HypervisorApi', 'GET', 'hypervisor/vms/{vm}', null, [A::HYPERVISOR_VIEW]],
            ['HypervisorApi', 'PUT', 'hypervisor/vms/{vm}', ['ignored' => true], [A::HYPERVISOR_MANAGE]],
            ['HypervisorApi', 'GET', 'hypervisor/vms?client_id={client}', null, [A::HYPERVISOR_VIEW]],
            ['AutoInstallApi', 'GET', '{d}/autoinstall', null, [A::SOFTWARE_VIEW]],
            ['AutoInstallApi', 'GET', 'software_autoinstall/selectable?client_id={client}', null, [A::SOFTWARE_VIEW]],
            ['AutoInstallApi', 'PUT', 'software_autoinstall/{pkg}', ['scope' => 'all'], [A::SOFTWARE_DEPLOY], null, 'fleet-wide', ['ctl' => 'admin']],
            ['AutoInstallApi', 'PUT', 'software_params/{pkgfix}', ['name' => 'LICENSE', 'value' => 'x', 'scope' => 'client', 'scope_id' => '{client}'], [A::SOFTWARE_MANAGE], [200, 422]],
            ['AutoInstallApi', 'DELETE', 'software_files/{fileid}', null, [A::SOFTWARE_MANAGE], [404], 'fleet-wide', ['ctl' => 'admin']],
        ];
        $rows = [];
        foreach ($routes as $i => $r) {
            [$api, $method, $path, $body, $ab] = $r;
            $control = $r[5] ?? null;
            $note = (string) ($r[6] ?? '');
            $o = ($r[7] ?? []) + ($control === null ? [] : ['control' => $control]);
            $name = $api . ' ' . $method . ' ' . $path . ($note !== '' ? " ($note)" : '');
            $m = $api === 'TechnicianApi' ? 'TechnicianApi::handle' : (str_starts_with($path, '{d') ? $api . '::device' : $api . '::fleet');
            $rows[] = self::row($m, $ab, 'REST: ' . ($note !== '' ? $note : 'looked up through the same client scope as the action it calls'), $o['w'] ?? $method !== 'GET',
                self::rest($method, $path, $body, !empty($o['reset'])), $o + ['rest' => true, 'name' => $name, 'idx' => $i]);
        }

        return $rows;
    }

    /** @return \Closure(E,string,RmmPrincipal):\Closure():RmmResponse */
    private static function rest(string $method, string $path, mixed $body, bool $resetPatches = false): \Closure
    {
        return function (E $e, string $t, RmmPrincipal $p) use ($method, $path, $body, $resetPatches): \Closure {
            if ($resetPatches) {
                $e->resetPatches();
            }
            $map = self::restTokens($e, $t, $path . json_encode($body));
            $subst = static function (mixed $v) use ($map, &$subst): mixed {
                if (is_array($v)) {
                    return array_map($subst, $v);
                }
                if (is_string($v)) {
                    $s = strtr($v, $map);

                    return $s !== $v && preg_match('/^\d+$/D', $s) === 1 ? (int) $s : $s;
                }

                return $v;
            };
            $q = [];
            $full = strtr($path, $map);
            if (str_contains($full, '?')) {
                [$full, $qs] = explode('?', $full, 2);
                parse_str($qs, $q);
            }
            $seg = array_values(array_filter(explode('/', $full), static fn (string $s): bool => $s !== ''));
            $b = $body === null ? null : $subst($body);

            return fn () => $e->h->tech($method, $seg, $p, $b, array_map('strval', $q))[2];
        };
    }

    /** @return array<string,string> */
    private static function restTokens(E $e, string $t, string $template): array
    {
        $map = ['{d}' => (string) $e->dev($t), '{dn}' => (string) $e->dev($t), '{dA}' => (string) $e->devA, '{client}' => (string) $e->client($t), '{ownclient}' => (string) $e->clientA(),
            '{scriptid}' => (string) $e->scriptId, '{pkgfix}' => (string) $e->packageId, '{fieldid}' => (string) $e->fieldDevice, '{policyfix}' => (string) $e->policyId,
            '{groupfix}' => (string) $e->groupId, '{fileid}' => (string) E::NO_ID];
        $keys = ['{job}' => 'job', '{tooljob}' => 'tooljob', '{tid}' => 'transfer', '{alert}' => 'alert', '{appr}' => 'approval', '{ad}' => 'assign_device', '{ac}' => 'assign_client',
            '{win}' => 'window_client', '{wind}' => 'window_device', '{esc}' => 'esc_client', '{lr}' => 'log_rule', '{cr}' => 'change_rule', '{pac}' => 'pol_assign_client',
            '{pad}' => 'pol_assign_device', '{sched}' => 'schedule', '{vm}' => 'vm', '{pkg}' => 'package', '{script}' => 'script', '{policy}' => 'policy', '{field}' => 'field',
            '{group}' => 'group', '{prule}' => 'patch_rule', '{ppolicy}' => 'ppolicy'];
        foreach ($keys as $token => $key) {
            if (str_contains($template, $token)) {
                $map[$token] = (string) $e->get($t, $key);
            }
        }
        if (str_contains($template, '{dq}')) {
            $map['{dq}'] = (string) $e->get($t, 'device');
        }
        if (str_contains($template, '{dgrp}')) {
            $d = $e->dev($t);
            if ($t === E::OWN) {
                $d = $e->get('own', 'device');
                $e->h->module->inventory()->addGroupDevices($e->admin, $e->groupId, [$d]);
            }
            $map['{dgrp}'] = (string) $d;
        }
        if (str_contains($template, '{dfresh}')) {
            $map['{dfresh}'] = (string) $e->get('own', 'device');
        }
        if (str_contains($template, '{pkgnew}')) {
            $map['{pkgnew}'] = (string) $e->newPackage();
        }

        return $map;
    }

    // ------------------------------------------------------------------ the tests

    #[DataProvider('matrix')]
    public function testOutOfScopeIsRefusedIdenticallyAndChangesNothing(string $name): void
    {
        $row = self::rows()[$name];
        $e = self::env();
        $answers = [];
        $e->meshUrl(($row['mesh'] ?? '') === 'https');
        foreach ([E::FOREIGN, E::MISSING] as $t) {
            $act = $row['call']($e, $t, $e->tech);
            $before = $e->snapshot();
            $r = self::out($act());
            $after = $e->snapshot();
            $answers[$t] = $r;
            $changed = E::diff($before, $after);
            $this->assertSame([], $changed, "$name [$t]: an out-of-scope request must change nothing, but changed " . implode(', ', $changed) . ' (answer ' . $r[0] . ' ' . $r[1] . ')');
            if (!empty($row['list']) || !empty($row['public'])) {
                $this->assertStringNotContainsString('TENANT-B', (string) json_encode($r), "$name [$t]: nothing of client B may appear");
                if (!empty($row['list'])) {
                    $this->assertTrue($r[3], "$name [$t]: a list read answers 200 with nothing in it");
                }
                continue;
            }
            $this->assertFalse($r[3], "$name [$t]: must be refused, answered {$r[0]} {$r[1]} {$r[2]}");
            $this->assertContains($r[0], $row['refuse'] ?? [403, 404], "$name [$t]: refusal status ({$r[0]} {$r[1]} {$r[2]})");
        }
        $e->meshUrl(false);
        if (empty($row['public'])) {
            $this->assertSame($answers[E::MISSING], $answers[E::FOREIGN], "$name: a missing id and another client's id must get the same answer (status, code, text)");
        }
    }

    #[DataProvider('matrix')]
    public function testPositiveControlTheSamePrincipalCanActOnItsOwnClient(string $name): void
    {
        $row = self::rows()[$name];
        $e = self::env();
        $who = ($row['ctl'] ?? '') === 'admin' ? $e->admin : $e->tech;
        $e->meshUrl(($row['mesh'] ?? '') === 'https');
        $act = $row['call']($e, E::OWN, $who);
        $before = $e->snapshot();
        $r = self::out($act());
        $after = $e->snapshot();
        $e->meshUrl(false);
        if (isset($row['control'])) {
            $this->assertContains($r[0], $row['control'], "$name: control answered {$r[0]} {$r[1]} {$r[2]}");
        } else {
            $this->assertTrue($r[3], "$name: the control (own client) must succeed, answered {$r[0]} {$r[1]} {$r[2]}");
        }
        if (!empty($row['w']) && $r[3]) {
            $this->assertNotSame([], E::diff($before, $after), "$name: a writing control must change the snapshot (else the test cannot see side effects)");
        }
    }

    /** The collections a caller limited to client A reads: they list A's objects (the control) and none of client B's. */
    public function testFleetListsShowOnlyTheObjectsOfTheCallersClients(): void
    {
        $e = self::env();
        $cases = [
            ['schedules', 'data', 'schedule_id', ['schedule'], 'schedule'],
            ['approvals', 'data', 'approval_id', ['approval'], 'approval'],
            ['maintenance', 'data', 'window_id', ['window_client', 'window_device'], 'window_client'],
            ['escalation_policies', 'data', 'policy_id', ['esc_client'], 'esc_client'],
            ['logs/rules', 'data', 'rule_id', ['log_rule'], 'log_rule'],
            ['changes/rules', 'data', 'rule_id', ['change_rule'], 'change_rule'],
            ['software_assignments', 'assignments', 'assignment_id', ['assign_device', 'assign_client'], 'assign_client'],
            ['alerts', 'items', 'alert_id', ['alert'], 'alert'],
            ['hypervisor/vms', 'data', 'vm_id', ['vm'], 'vm'],
        ];
        foreach ($cases as [$path, $listKey, $idKey, $bKeys, $ownKey]) {
            $own = $e->get(E::OWN, $ownKey);
            [$c, $body] = $e->h->tech('GET', explode('/', $path), $e->tech);
            $this->assertSame(200, $c, $path);
            $ids = array_map('intval', array_column($body[$listKey] ?? [], $idKey));
            $this->assertContains((int) $own, $ids, "$path: the control: the list shows the caller's own object");
            foreach ($bKeys as $k) {
                $this->assertNotContains((int) $e->b[$k], $ids, "$path: must not list the object of client B ($k)");
            }
        }
        [$c, $body] = $e->h->tech('GET', [], $e->tech);
        $this->assertSame(200, $c);
        $this->assertStringContainsString('TENANT-A-PC', (string) json_encode($body));
        $this->assertStringNotContainsString('TENANT-B', (string) json_encode($body));
    }

    /** Policies are fleet-wide, but who they are assigned to is the business of those clients. */
    public function testPolicyDetailsHideTheAssignmentsOfOtherClients(): void
    {
        $e = self::env();
        $ownClient = $e->get(E::OWN, 'pol_assign_client');
        $ownDevice = $e->get(E::OWN, 'pol_assign_device');
        [$c, $body] = $e->h->tech('GET', ['policies', (string) $e->policyId], $e->tech);
        $this->assertSame(200, $c);
        $ids = array_map('intval', array_column($body['assignments'], 'assignment_id'));
        $this->assertContains((int) $ownClient, $ids, 'the control: the caller\'s own assignments are listed');
        $this->assertContains((int) $ownDevice, $ids);
        $this->assertNotContains((int) $e->b['pol_assign_client'], $ids, 'an assignment to client B is not listed');
        $this->assertNotContains((int) $e->b['pol_assign_device'], $ids, 'an assignment to a device of client B is not listed');

        $pa = $e->h->module->patchActions();
        $e->h->module->patchActions()->assignPolicy($e->admin, $e->patchPolicyId, ['scope_type' => 'device', 'scope_id' => $e->devA, 'priority' => 3]);
        $e->h->module->patchActions()->assignPolicy($e->admin, $e->patchPolicyId, ['scope_type' => 'device', 'scope_id' => $e->devB, 'priority' => 4]);
        $scopes = static fn (array $assignments): array => array_map(static fn (array $a): string => $a['scope_type'] . ':' . $a['scope_id'], $assignments);
        foreach ([$pa->getPolicy($e->tech, $e->patchPolicyId)->data['policy']['assignments'], array_values(array_filter($pa->listPolicies($e->tech)->data['policies'], static fn (array $p): bool => $p['policy_id'] === $e->patchPolicyId))[0]['assignments']] as $assignments) {
            $this->assertContains('client:' . $e->clientA(), $scopes($assignments), 'the control: a patch policy lists the assignment to the caller\'s client');
            $this->assertContains('device:' . $e->devA, $scopes($assignments));
            $this->assertNotContains('client:' . $e->clientB(), $scopes($assignments), 'a patch policy does not list the assignment to client B');
            $this->assertNotContains('device:' . $e->devB, $scopes($assignments), 'nor the assignment to a device of client B');
        }
    }

    public function testEveryIdBearingMethodIsInTheMatrix(): void
    {
        $covered = [];
        foreach (self::rows() as $row) {
            $covered[preg_replace('/#.*$/', '', $row['m'])] = true;
        }
        $missing = [];
        $stale = [];
        $seen = [];
        foreach (self::CLASSES as $class) {
            $rc = new \ReflectionClass($class);
            $short = $rc->getShortName();
            foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->isConstructor() || $m->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $key = $short . '::' . $m->getName();
                $seen[$key] = true;
                $idLike = array_filter($m->getParameters(), static fn (\ReflectionParameter $p): bool => self::idLike($p->getName()));
                $inAllow = isset(self::NOT_CLIENT_SCOPED[$key]);
                if ($idLike !== [] && !isset($covered[$key]) && !$inAllow) {
                    $missing[] = $key . '(' . implode(', ', array_map(static fn (\ReflectionParameter $p): string => '$' . $p->getName(), $idLike)) . ')';
                }
                if ($inAllow && isset($covered[$key])) {
                    $stale[] = "$key is in the matrix AND in NOT_CLIENT_SCOPED";
                }
            }
        }
        foreach (array_keys(self::NOT_CLIENT_SCOPED) as $k) {
            if (!isset($seen[$k])) {
                $stale[] = "$k is in NOT_CLIENT_SCOPED but is not a public method of a guarded class";
            }
        }
        foreach (array_keys($covered) as $k) {
            if (!isset($seen[$k])) {
                $stale[] = "matrix row for $k, which is not a public method of a guarded class";
            }
        }
        foreach (self::NOT_CLIENT_SCOPED as $k => $why) {
            $this->assertGreaterThan(30, strlen($why), "$k: give the reason");
        }
        $this->assertSame([], $missing, "Public methods with an id-like parameter that are neither in the client-scope matrix nor in NOT_CLIENT_SCOPED (add a row, or an allow-list entry with the reason):\n" . implode("\n", $missing));
        $this->assertSame([], $stale, implode("\n", $stale));
    }

    public function testEveryRestRouteWordIsInTheMatrixOrHasNoId(): void
    {
        $paths = '';
        foreach (self::rows() as $row) {
            if (!empty($row['rest'])) {
                $paths .= ' ' . $row['name'];
            }
        }
        $missing = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Rmm/Http/*Api.php') ?: [] as $file) {
            if (str_ends_with($file, 'DeviceApi.php') || str_ends_with($file, 'HostedFileApi.php')) {
                continue;   // the agent's own endpoints (device token) and the token-gated hosted file download
            }
            preg_match_all("/case '([a-z_]+)'|(?:\\\$what|\\\$kind|\\\$a|\\\$b|\\\$sub|\\\$sub2|\\\$second|\\\$third|\\\$op|\\\$job|\\\$seg\\[\\d\\]) (?:===|!==) '([a-z_]+)'/", (string) file_get_contents($file), $mm);
            foreach (array_filter(array_merge($mm[1], $mm[2])) as $word) {
                if (!in_array($word, self::ROUTE_WORDS_WITHOUT_ID, true) && preg_match('#(^|[ /])' . preg_quote($word, '#') . '([ /?]|$)#', $paths) !== 1) {
                    $missing[basename($file) . ' ' . $word] = true;
                }
            }
        }
        $this->assertSame([], array_keys($missing), 'A REST route word has no row in restRows() and is not in ROUTE_WORDS_WITHOUT_ID: ' . implode(', ', array_keys($missing)));
    }

    public function testTheDocsListEveryMethodOfTheMatrix(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/modules/rmm.md');
        $this->assertStringContainsString('## Client scoping matrix', $doc);
        $section = substr($doc, (int) strpos($doc, '## Client scoping matrix'));
        $missing = [];
        foreach (self::rows() as $row) {
            $m = preg_replace('/#.*$/', '', $row['m']);
            if (empty($row['rest']) && !str_contains($section, '`' . $m . '`')) {
                $missing[$m] = true;
            }
        }
        foreach (array_keys(self::NOT_CLIENT_SCOPED) as $k) {
            if (!str_contains($section, '`' . $k . '`')) {
                $missing[$k] = true;
            }
        }
        $this->assertSame([], array_keys($missing), 'docs/modules/rmm.md "Client scoping matrix" must list: ' . implode(', ', array_keys($missing)));
    }

    private static function idLike(string $name): bool
    {
        return !in_array($name, ['userId', 'userIds'], true) && preg_match('/^(id|tid)$|Ids?$|_ids?$/', $name) === 1;
    }
}
