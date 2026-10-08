<?php

declare(strict_types=1);

namespace RivetCore\Rmm;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Jobs\JobQueue;
use RivetCore\Jobs\JobWorker;
use RivetCore\Rmm\Capacity\CapacityReport;
use RivetCore\Rmm\Capacity\IngestQueue;
use RivetCore\Rmm\Capacity\LoadShedder;
use RivetCore\Rmm\Admin\RmmAdmin;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Binaries\BinaryStore;
use RivetCore\Rmm\Checkin\CheckinService;
use RivetCore\Rmm\Checks\CheckEvaluator;
use RivetCore\Rmm\Contracts\RmmAssetNamesInterface;
use RivetCore\Rmm\Contracts\RmmAssetsInterface;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Rmm\Contracts\RmmModuleStateInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Device\DeviceService;
use RivetCore\Rmm\Enrollment\AttemptLog;
use RivetCore\Rmm\Enrollment\EnrollmentService;
use RivetCore\Rmm\Http\DeviceApi;
use RivetCore\Rmm\Http\TechnicianApi;
use RivetCore\Rmm\Installer\InstallerDownload;
use RivetCore\Rmm\Installer\InstallerService;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Job\JobTypeRegistry;
use RivetCore\Rmm\Link\RmmLinker;
use RivetCore\Rmm\Maintenance\Housekeeping;
use RivetCore\Rmm\Mesh\MeshService;
use RivetCore\Rmm\Read\RmmReadModel;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\NullRmmAudit;
use RivetCore\Rmm\Support\NullRmmMetricSink;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Technician\TechnicianActions;
use RivetCore\Rmm\Update\UpdateService;
use RivetCore\Webhooks\UrlPolicy;

/**
 * The composition root: builds the module's services from the storage contract and the edition's adapters, once, lazily. The
 * edition's five-line REST bridges call {@see deviceApi()}; its cron entry calls {@see housekeeping()}.
 *
 * Options: `binary_dir` (where hosted agent binaries live, default none), `allow_insecure_http` (loopback test servers only),
 * `allow_linux` (admit the Linux test agent), `host_fallback`, `integration_name`, `installer_prefix`, `max_upload_bytes` (size cap of one
 * hosted agent binary, default 64 MiB), `client_label` (what the edition calls a client in user-facing text, default "client"; RivetIT: "department"),
 * `denial_reasons` (ability => sentence, replaces the generic denial text of that ability, see {@see RmmAuthorizer}).
 *
 * The technician side ({@see technicianApi()}, {@see technician()}, {@see admin()}, {@see readModel()}) needs the edition's
 * AccessPolicyInterface (and may be given a UrlPolicy for the MeshCentral probe; the default refuses private addresses).
 *
 * @api
 */
final class RmmModule
{
    private ?Sql $sql = null;
    private ?RmmSettings $settings = null;
    private ?DeviceRepository $devices = null;
    private ?JobService $jobs = null;
    private ?CheckEvaluator $checks = null;
    private ?RmmLinker $linker = null;
    private ?UpdateService $updates = null;
    private ?EnrollmentService $enrollment = null;
    private ?AttemptLog $attempts = null;
    private ?RmmState $stateReader = null;
    private ?IngestQueue $ingestQueue = null;
    private ?LoadShedder $shedder = null;
    private ?CapacityReport $capacity = null;
    private ?RmmAuthorizer $authorizer = null;
    private ?RmmReadModel $readModel = null;
    private ?BinaryStore $binaryStore = null;
    private ?MeshService $mesh = null;
    private ?InstallerService $installerService = null;
    private ?TechnicianActions $technician = null;
    private ?RmmAdmin $admin = null;

    private readonly RmmAuditInterface $audit;
    private readonly RmmMetricSinkInterface $metrics;

    /**
     * @param array{binary_dir?:?string,allow_insecure_http?:bool,allow_linux?:bool,host_fallback?:?string,integration_name?:string,installer_prefix?:string,max_upload_bytes?:?int,client_label?:string,denial_reasons?:array<string,string>} $options
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ClockInterface $clock,
        private readonly RmmTenancyInterface $tenancy,
        private readonly RmmAssetsInterface $assets,
        private readonly RmmBridgeInterface $bridge,
        private readonly SecretBoxInterface $box,
        ?RmmAuditInterface $audit = null,
        ?RmmMetricSinkInterface $metrics = null,
        private readonly ?RmmModuleStateInterface $state = null,
        private readonly array $options = [],
        private readonly ?JobTypeRegistry $registry = null,
        private readonly ?AccessPolicyInterface $policy = null,
        private readonly ?UrlPolicy $urlPolicy = null,
    ) {
        $this->audit = $audit ?? new NullRmmAudit();
        $this->metrics = $metrics ?? new NullRmmMetricSink();
    }

    /**
     * What this edition calls a client in text a user reads ("client", RivetIT: "department"): option `client_label`, lower case,
     * letters and spaces only (anything else falls back to "client").
     */
    public function clientLabel(): string
    {
        $l = $this->options['client_label'] ?? 'client';

        return preg_match('/^[a-z][a-z ]{0,29}$/', $l) === 1 ? $l : 'client';
    }

    public function sql(): Sql
    {
        return $this->sql ??= new Sql($this->database, $this->clock);
    }

    public function settings(): RmmSettings
    {
        if ($this->settings === null) {
            $this->settings = new RmmSettings($this->sql(), $this->box, $this->bridge, $this->options['integration_name'] ?? RmmProtocol::DEFAULT_INTEGRATION_NAME, $this->options['allow_insecure_http'] ?? false);
            // Every change of a mirrored setting rewrites the zero-database state file in the same request.
            $this->settings->onStateChange(function (): void {
                $this->state()->sync();
            });
        }

        return $this->settings;
    }

    /** The effective module switch (state file first, database second), see {@see RmmState}. */
    public function state(): RmmState
    {
        return $this->stateReader ??= new RmmState($this->settings(), $this->sql(), $this->state);
    }

    /**
     * Rewrite the state file from the database (and the edition's kill switch). The edition calls this after IT changes its own flag
     * (RivetMSP: config_core_rmm_enabled), which Core cannot observe; Core calls it itself after every settings change.
     */
    public function syncState(): bool
    {
        return $this->state()->sync();
    }

    public function devices(): DeviceRepository
    {
        return $this->devices ??= new DeviceRepository($this->sql(), $this->settings());
    }

    public function jobs(): JobService
    {
        return $this->jobs ??= new JobService($this->sql(), $this->settings(), $this->registry ?? JobTypeRegistry::withDefaults());
    }

    public function checks(): CheckEvaluator
    {
        return $this->checks ??= new CheckEvaluator($this->sql(), $this->settings(), $this->bridge, $this->devices());
    }

    public function linker(): RmmLinker
    {
        return $this->linker ??= new RmmLinker($this->sql(), $this->settings(), $this->bridge, $this->assets);
    }

    public function updates(): UpdateService
    {
        return $this->updates ??= new UpdateService($this->sql(), $this->settings(), $this->audit, $this->options['binary_dir'] ?? null, $this->options['allow_insecure_http'] ?? false, $this->options['host_fallback'] ?? null);
    }

    public function attempts(): AttemptLog
    {
        return $this->attempts ??= new AttemptLog($this->sql());
    }

    public function enrollment(): EnrollmentService
    {
        return $this->enrollment ??= new EnrollmentService($this->sql(), $this->settings(), $this->assets, $this->tenancy, $this->linker(), $this->audit, $this->attempts(), $this->options['allow_linux'] ?? false, $this->clientLabel());
    }

    public function deviceService(): DeviceService
    {
        return new DeviceService($this->sql(), $this->devices(), $this->settings(), $this->jobs(), $this->checks(), $this->linker(), $this->bridge, $this->assets, $this->tenancy, $this->audit, $this->enrollment());
    }

    public function checkin(): CheckinService
    {
        return new CheckinService($this->sql(), $this->settings(), $this->devices(), $this->checks(), $this->jobs(), $this->updates(), $this->linker(), $this->assets, $this->metrics,
            function (array $work): void {
                $this->ingestQueue()->enqueue($work);
            });
    }

    /** Queued ingest (`rmm.ingest` jobs on Core's JobQueue): enqueue, batch worker, backlog metric. */
    public function ingestQueue(): IngestQueue
    {
        return $this->ingestQueue ??= new IngestQueue(new JobQueue($this->database), $this->database, $this->checkin(), $this->metrics, $this->settings(), $this->state());
    }

    /** The staged load shedder (cron: {@see LoadShedder::evaluate()}; request path: {@see LoadShedder::tick()}). */
    public function shedder(): LoadShedder
    {
        return $this->shedder ??= new LoadShedder($this->sql(), $this->settings(), $this->ingestQueue(), $this->state(), $this->audit);
    }

    /** Data of the "Performance and capacity" panel. */
    public function capacity(): CapacityReport
    {
        return $this->capacity ??= new CapacityReport($this->sql(), $this->settings(), $this->ingestQueue(), $this->state(), $this->shedder());
    }

    /**
     * Register the `rmm.*` job handlers on Core's job worker. They are always registered, and each releases its job (no attempt spent)
     * while the module is off, so disabling never dead-letters queued work. An edition whose cron finds the state file off may skip
     * the whole worker run instead (and should, to keep the disabled module at zero cost).
     */
    public function registerHandlers(JobWorker $worker): void
    {
        $this->ingestQueue()->register($worker);
    }

    public function installerDownload(): InstallerDownload
    {
        return new InstallerDownload($this->sql(), $this->settings(), $this->updates(), $this->tenancy, $this->audit, $this->attempts(), $this->options['installer_prefix'] ?? RmmProtocol::INSTALLER_NAME_PREFIX, $this->clientLabel());
    }

    public function housekeeping(): Housekeeping
    {
        return new Housekeeping($this->sql(), $this->settings(), $this->bridge, $this->jobs(), null, $this->shedder(), $this->ingestQueue());
    }

    // ------------------------------------------------------------------ technician and administration side

    /** Who may do what: the edition's AccessPolicy plus its tenancy scope, with the module switch. @throws \LogicException without a policy */
    public function authorizer(): RmmAuthorizer
    {
        if ($this->policy === null) {
            throw new \LogicException('The RMM technician side needs an AccessPolicyInterface: pass it as the policy argument of RmmModule.');
        }

        return $this->authorizer ??= new RmmAuthorizer($this->policy, $this->tenancy, fn (): bool => $this->enabled(), $this->clientLabel(), $this->options['denial_reasons'] ?? []);
    }

    /** Everything the technician REST API and the administration pages read, as arrays. */
    public function readModel(): RmmReadModel
    {
        return $this->readModel ??= new RmmReadModel($this->sql(), $this->settings(), $this->devices(), $this->updates(), $this->binaryStore(),
            $this->assets instanceof RmmAssetNamesInterface ? $this->assets : null, $this->clientLabel());
    }

    /** Hosted agent binaries: validate, store, publish, serve. */
    public function binaryStore(): BinaryStore
    {
        return $this->binaryStore ??= new BinaryStore($this->sql(), $this->updates(), $this->audit, $this->options['binary_dir'] ?? null, $this->options['max_upload_bytes'] ?? null);
    }

    /** MeshCentral remote access (URL checks, probe through the UrlPolicy, launch). */
    public function mesh(): MeshService
    {
        return $this->mesh ??= new MeshService($this->sql(), $this->settings(), $this->devices(), $this->box, $this->urlPolicy ?? new UrlPolicy(false), $this->options['allow_insecure_http'] ?? false);
    }

    /** Per-client installers and deployment snippets (the administrator side). */
    public function installerService(): InstallerService
    {
        return $this->installerService ??= new InstallerService($this->sql(), $this->updates(), $this->installerDownload(), $this->enrollment(), $this->tenancy, $this->audit, $this->binaryStore(), $this->clientLabel());
    }

    /** Technician and administrator actions on devices, shared by the web handlers and the REST API. */
    public function technician(): TechnicianActions
    {
        return $this->technician ??= new TechnicianActions($this->sql(), $this->devices(), $this->deviceService(), $this->enrollment(), $this->jobs(), $this->updates(),
            $this->mesh(), $this->authorizer(), $this->bridge, $this->audit, $this->clientLabel());
    }

    /** The validated administration operations (settings, MeshCentral, signing key, binaries, releases, installers). */
    public function admin(): RmmAdmin
    {
        return $this->admin ??= new RmmAdmin($this->sql(), $this->settings(), $this->box, $this->authorizer(), $this->binaryStore(), $this->updates(), $this->mesh(),
            $this->installerService(), $this->devices(), $this->audit);
    }

    /** The technician REST API handler (`endpoint_devices`): the edition authenticates and passes the principal. */
    public function technicianApi(): TechnicianApi
    {
        return new TechnicianApi($this->authorizer(), $this->technician(), $this->readModel());
    }

    /**
     * @param \Closure(string,int,int):bool $rateLimit (bucket, limit, windowSeconds): true when the call is within budget
     * @param bool $withModuleState legacy switch, kept for editions that call it positionally: false is the same as $disabledAnswer
     *        'compat'. Ignored when $disabledAnswer is given
     * @param (\Closure(int):void)|null $sleep long-poll pause
     * @param (\Closure():float)|null $now monotonic seconds for the long-poll deadline
     * @param string|null $disabledAnswer what a switched-off module answers: {@see DeviceApi::DISABLED_UNIFORM} (503 module_disabled, the
     *        default) or {@see DeviceApi::DISABLED_COMPAT} (403 forbidden, RivetIT's goldens). In both modes a missing state file is re-created
     */
    public function deviceApi(\Closure $rateLimit, bool $withModuleState = true, ?\Closure $sleep = null, ?\Closure $now = null, ?string $disabledAnswer = null): DeviceApi
    {
        return new DeviceApi(
            $this->settings(), $this->devices(), $this->enrollment(), $this->checkin(), $this->jobs(), $this->updates(),
            $this->installerDownload(), $this->audit, $rateLimit, $this->options['allow_insecure_http'] ?? false,
            $this->state, $sleep, $now, $this->shedder(),
            $disabledAnswer ?? ($withModuleState ? DeviceApi::DISABLED_UNIFORM : DeviceApi::DISABLED_COMPAT), $this->state(),
        );
    }

    /** Edition kill switch AND the master switch: from the state file when there is a valid one, from the database otherwise. */
    public function enabled(): bool
    {
        return $this->state()->enabled();
    }

    /** True when the module is on and the named sub-switch is (state file first, database second). */
    public function featureOn(string $feature): bool
    {
        return $this->state()->featureOn($feature);
    }
}
