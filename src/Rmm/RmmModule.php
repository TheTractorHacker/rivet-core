<?php

declare(strict_types=1);

namespace RivetCore\Rmm;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Jobs\JobQueue;
use RivetCore\Jobs\JobWorker;
use RivetCore\Rmm\Alerting\AlertingActions;
use RivetCore\Rmm\Alerting\AlertingEngine;
use RivetCore\Rmm\Alerting\AlertingHousekeeping;
use RivetCore\Rmm\Alerting\AlertService;
use RivetCore\Rmm\Alerting\CheckEvalStore;
use RivetCore\Rmm\Alerting\DefaultThresholdResolver;
use RivetCore\Rmm\Alerting\DependencyService;
use RivetCore\Rmm\Alerting\EscalationService;
use RivetCore\Rmm\Alerting\MaintenanceScheduleGate;
use RivetCore\Rmm\Alerting\MaintenanceService;
use RivetCore\Rmm\Alerting\ScopeMatcher;
use RivetCore\Rmm\Alerting\StormControl;
use RivetCore\Rmm\Alerting\ThresholdResolverInterface;
use RivetCore\Rmm\Capacity\CapacityReport;
use RivetCore\Rmm\Capacity\IngestQueue;
use RivetCore\Rmm\Capacity\LoadShedder;
use RivetCore\Rmm\Admin\RmmAdmin;
use RivetCore\Rmm\Approvals\ApprovalService;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Binaries\BinaryStore;
use RivetCore\Rmm\Checkin\CheckinService;
use RivetCore\Rmm\Checks\CheckEvaluator;
use RivetCore\Rmm\Contracts\RmmAssetNamesInterface;
use RivetCore\Rmm\Contracts\RmmAssetsInterface;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Rmm\Contracts\RmmEscalationInterface;
use RivetCore\Rmm\Contracts\RmmEventsInterface;
use RivetCore\Rmm\Contracts\RmmMetricReaderInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Rmm\Contracts\RmmModuleStateInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Contracts\ScheduleGateInterface;
use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Device\DeviceState;
use RivetCore\Rmm\Device\DeviceService;
use RivetCore\Rmm\Enrollment\AttemptLog;
use RivetCore\Rmm\Enrollment\EnrollmentService;
use RivetCore\Rmm\Fields\CustomFieldService;
use RivetCore\Rmm\Http\DeviceApi;
use RivetCore\Rmm\Http\TechnicianApi;
use RivetCore\Rmm\Installer\InstallerDownload;
use RivetCore\Rmm\Installer\InstallerService;
use RivetCore\Rmm\Job\JobExtras;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Job\JobTypeRegistry;
use RivetCore\Rmm\Link\RmmLinker;
use RivetCore\Rmm\Maintenance\Housekeeping;
use RivetCore\Rmm\Mesh\MeshService;
use RivetCore\Rmm\Policy\EffectivePolicy;
use RivetCore\Rmm\Policy\PolicyStore;
use RivetCore\Rmm\Read\AutomationReader;
use RivetCore\Rmm\Read\RmmReadModel;
use RivetCore\Rmm\Scripts\ApprovedRunExecutor;
use RivetCore\Rmm\Scripts\BulkRunner;
use RivetCore\Rmm\Scripts\ScheduleRunner;
use RivetCore\Rmm\Scripts\ScheduleService;
use RivetCore\Rmm\Scripts\ScriptRunner;
use RivetCore\Rmm\Scripts\ScriptService;
use RivetCore\Rmm\Scripts\TargetResolver;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Software\SoftwareService;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Rmm\Support\NullRmmAudit;
use RivetCore\Rmm\Support\NullRmmEscalation;
use RivetCore\Rmm\Support\NullRmmEvents;
use RivetCore\Rmm\Support\NullRmmMetricSink;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Tags\GroupService;
use RivetCore\Rmm\Tags\TagService;
use RivetCore\Rmm\Technician\FieldActions;
use RivetCore\Rmm\Technician\InventoryActions;
use RivetCore\Rmm\Technician\PolicyActions;
use RivetCore\Rmm\Technician\ScriptActions;
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
 * `denial_reasons` (ability => sentence, replaces the generic denial text of that ability, see {@see RmmAuthorizer}), `escalation` (a
 * {@see RmmEscalationInterface}: the edition's delivery of escalation notices, default none) and `threshold_resolver` (a
 * {@see ThresholdResolverInterface}: which thresholds apply to a check on a device, default the check definition plus the per-device override).
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
    private ?DeviceState $deviceState = null;
    private ?SoftwareService $software = null;
    private ?RmmEventPublisher $eventPublisher = null;
    private ?TagService $tagService = null;
    private ?GroupService $groupService = null;
    private ?InventoryActions $inventory = null;
    private ?AlertingEngine $alertingEngine = null;
    private ?MaintenanceService $maintenanceService = null;
    private ?ScopeMatcher $scopeMatcher = null;
    private ?EscalationService $escalationService = null;
    private ?AlertService $alertService = null;
    private ?DependencyService $dependencyService = null;
    private ?StormControl $stormControl = null;
    private ?CheckEvalStore $checkEvalStore = null;
    private ?AlertingActions $alertingActions = null;
    private ?\RivetCore\Rmm\Http\AlertingApi $alertingApi = null;
    private ?PolicyStore $policyStore = null;
    private ?EffectivePolicy $effectivePolicy = null;
    private ?JobExtras $jobExtras = null;
    private ?ScriptService $scriptService = null;
    private ?CustomFieldService $fieldService = null;
    private ?ApprovalService $approvalService = null;
    private ?TargetResolver $targetResolver = null;
    private ?ScriptRunner $scriptRunner = null;
    private ?BulkRunner $bulkRunner = null;
    private ?ScheduleService $scheduleService = null;
    private ?ScheduleRunner $scheduleRunner = null;
    private ?ApprovedRunExecutor $approvedRunExecutor = null;
    private ?AutomationReader $automationReader = null;
    private ?PolicyActions $policyActions = null;
    private ?ScriptActions $scriptActions = null;
    private ?FieldActions $fieldActions = null;
    private ?ScheduleGateInterface $scheduleGate = null;

    private readonly RmmAuditInterface $audit;
    private readonly RmmMetricSinkInterface $metrics;
    private readonly RmmEventsInterface $events;

    /**
     * @param array{binary_dir?:?string,allow_insecure_http?:bool,allow_linux?:bool,host_fallback?:?string,integration_name?:string,installer_prefix?:string,max_upload_bytes?:?int,client_label?:string,denial_reasons?:array<string,string>,escalation?:RmmEscalationInterface,threshold_resolver?:ThresholdResolverInterface} $options
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
        ?RmmEventsInterface $events = null,
    ) {
        $this->audit = $audit ?? new NullRmmAudit();
        $this->metrics = $metrics ?? new NullRmmMetricSink();
        $this->events = $events ?? new NullRmmEvents();
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
        return $this->jobs ??= new JobService($this->sql(), $this->settings(), $this->registry ?? JobTypeRegistry::withDefaults(), $this->eventPublisher(), $this->jobExtras(), $this->deviceState());
    }

    public function checks(): CheckEvaluator
    {
        return $this->checks ??= new CheckEvaluator($this->sql(), $this->settings(), $this->bridge, $this->devices(), $this->eventPublisher(), $this->alertingEngine());
    }

    // ------------------------------------------------------------------ Phase 3: alerting

    /** Threshold tiers, flap dampening, maintenance, dependency and storm control behind the check evaluator (active only while the `alerting` sub-switch is on). */
    public function alertingEngine(): AlertingEngine
    {
        return $this->alertingEngine ??= new AlertingEngine($this->settings(), $this->checkEval(), $this->options['threshold_resolver'] ?? new DefaultThresholdResolver(),
            $this->maintenance(), $this->dependencies(), $this->storm(), $this->alerts());
    }

    /** Per-check threshold and flap state, and the per-device threshold override. */
    public function checkEval(): CheckEvalStore
    {
        return $this->checkEvalStore ??= new CheckEvalStore($this->sql());
    }

    public function scopes(): ScopeMatcher
    {
        return $this->scopeMatcher ??= new ScopeMatcher($this->sql());
    }

    /** Maintenance windows and the "which windows are open for this device" lookup (also for the Phase 2 scheduler). */
    public function maintenance(): MaintenanceService
    {
        return $this->maintenanceService ??= new MaintenanceService($this->sql(), $this->eventPublisher(), $this->scopes());
    }

    /** Escalation policies and the escalation clock. */
    public function escalation(): EscalationService
    {
        return $this->escalationService ??= new EscalationService($this->sql(), $this->settings(), $this->scopes(), $this->maintenance(), $this->options['escalation'] ?? new NullRmmEscalation(), $this->eventPublisher());
    }

    /** The alert lifecycle record: acknowledge, resolve, list, group. */
    public function alerts(): AlertService
    {
        return $this->alertService ??= new AlertService($this->sql(), $this->settings(), $this->bridge, $this->escalation(), $this->options['escalation'] ?? new NullRmmEscalation(), $this->eventPublisher());
    }

    /** Device parent links for dependency suppression. */
    public function dependencies(): DependencyService
    {
        return $this->dependencyService ??= new DependencyService($this->sql(), $this->settings());
    }

    /** Per-client and global alert rate caps. */
    public function storm(): StormControl
    {
        return $this->stormControl ??= new StormControl($this->sql(), $this->settings(), $this->bridge);
    }

    /** Technician actions of the alerting area (shared by the REST API and the edition's pages). */
    public function alertingActions(): AlertingActions
    {
        return $this->alertingActions ??= new AlertingActions($this->sql(), $this->devices(), $this->authorizer(), $this->alerts(), $this->maintenance(), $this->escalation(), $this->dependencies(),
            $this->storm(), $this->checkEval(), $this->settings(), $this->audit);
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
        return $this->enrollment ??= new EnrollmentService($this->sql(), $this->settings(), $this->assets, $this->tenancy, $this->linker(), $this->audit, $this->attempts(), $this->options['allow_linux'] ?? false, $this->clientLabel(), $this->eventPublisher());
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
            }, $this->deviceState(), $this->software(), $this->eventPublisher(), $this->effectivePolicy());
    }

    /** Capabilities, presence and software bookkeeping of a device (table rmm_device_state). */
    public function deviceState(): DeviceState
    {
        return $this->deviceState ??= new DeviceState($this->sql());
    }

    /** Delivers `rmm.*` events to the edition's {@see RmmEventsInterface} (after commit; a failing bus never fails the caller). */
    public function eventPublisher(): RmmEventPublisher
    {
        return $this->eventPublisher ??= new RmmEventPublisher($this->sql(), $this->events);
    }

    /** The software inventory: apply a report, current state, change log. */
    public function software(): SoftwareService
    {
        return $this->software ??= new SoftwareService($this->sql(), $this->deviceState(), $this->eventPublisher());
    }

    /** Tags on devices. */
    public function tags(): TagService
    {
        return $this->tagService ??= new TagService($this->sql());
    }

    /** Static device groups with tag membership. */
    public function groups(): GroupService
    {
        return $this->groupService ??= new GroupService($this->sql());
    }

    /** Tag, group and software actions of technicians (shared by the REST API and the edition's pages). */
    public function inventory(): InventoryActions
    {
        return $this->inventory ??= new InventoryActions($this->devices(), $this->authorizer(), $this->tags(), $this->groups(), $this->deviceState(), $this->audit);
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
        return new Housekeeping($this->sql(), $this->settings(), $this->bridge, $this->jobs(), null, $this->shedder(), $this->ingestQueue(), $this->eventPublisher(), $this->deviceState(),
            $this->metrics instanceof DatabaseMetricSink ? $this->metrics : null, $this->scheduleRunner(), $this->approvals(), $this->approvedRunExecutor(), $this->jobExtras(), $this->policyStore(),
            new AlertingHousekeeping($this->maintenance(), $this->escalation(), $this->alerts(), $this->storm(), $this->checkEval()));
    }

    // ------------------------------------------------------------------ Phase 2: policies, script library, schedules, approvals, custom fields

    /** Policies and their assignments (tables rmm_policies, rmm_policy_assignments). */
    public function policyStore(): PolicyStore
    {
        return $this->policyStore ??= new PolicyStore($this->sql(), $this->tenancy);
    }

    /** What one device is told, given the policies that reach it (used by the check-in; the `policies` sub-switch must be on). */
    public function effectivePolicy(): EffectivePolicy
    {
        return $this->effectivePolicy ??= new EffectivePolicy($this->settings(), $this->policyStore());
    }

    /** The sidecar of library jobs (script, schedule, approval, idempotency key, sealed secret parameters). */
    public function jobExtras(): JobExtras
    {
        return $this->jobExtras ??= new JobExtras($this->sql(), $this->box);
    }

    /** The script library: versions signed with the instance key. */
    public function scripts(): ScriptService
    {
        return $this->scriptService ??= new ScriptService($this->sql(), $this->settings());
    }

    /** Custom fields and their values. */
    public function customFields(): CustomFieldService
    {
        return $this->fieldService ??= new CustomFieldService($this->sql(), $this->box, $this->tenancy);
    }

    /** Two-person approval requests. */
    public function approvals(): ApprovalService
    {
        return $this->approvalService ??= new ApprovalService($this->sql(), $this->settings());
    }

    /** Which devices a run or schedule target reaches. */
    public function targets(): TargetResolver
    {
        return $this->targetResolver ??= new TargetResolver($this->sql(), $this->tenancy);
    }

    /** Library script plus parameters to a queued job on one device. */
    public function scriptRunner(): ScriptRunner
    {
        return $this->scriptRunner ??= new ScriptRunner($this->jobs(), $this->customFields(), $this->eventPublisher());
    }

    public function bulkRunner(): BulkRunner
    {
        return $this->bulkRunner ??= new BulkRunner($this->targets(), $this->scriptRunner(), $this->sql());
    }

    /** Scheduled scripts: definitions and history. */
    public function schedules(): ScheduleService
    {
        return $this->scheduleService ??= new ScheduleService($this->sql(), $this->settings(), $this->scripts(), $this->scriptRunner(), $this->targets());
    }

    /**
     * Give scheduled scripts a say before they start on a device (the alerting phase's maintenance windows). Call it once, before the first
     * {@see housekeeping()} of the process; the default lets everything run.
     */
    public function setScheduleGate(ScheduleGateInterface $gate): void
    {
        $this->scheduleGate = $gate;
        $this->scheduleRunner = null;
    }

    public function scheduleRunner(): ScheduleRunner
    {
        return $this->scheduleRunner ??= new ScheduleRunner($this->sql(), $this->settings(), $this->scripts(), $this->scriptRunner(), $this->targets(), $this->jobExtras(),
            $this->scheduleGate ?? new MaintenanceScheduleGate($this->maintenance(), $this->settings()), $this->audit);
    }

    public function approvedRunExecutor(): ApprovedRunExecutor
    {
        return $this->approvedRunExecutor ??= new ApprovedRunExecutor($this->sql(), $this->settings(), $this->approvals(), $this->scripts(), $this->bulkRunner(), $this->tenancy, $this->audit);
    }

    /** The Phase 2 reads (also reachable as `readModel()->automation()`). */
    public function automationReader(): AutomationReader
    {
        return $this->automationReader ??= new AutomationReader($this->sql(), $this->policyStore(), $this->effectivePolicy(), $this->scripts(), $this->approvals(), $this->schedules(), $this->customFields(), $this->deviceState());
    }

    /** Policy administration (needs the edition's AccessPolicy). */
    public function policyActions(): PolicyActions
    {
        return $this->policyActions ??= new PolicyActions($this->authorizer(), $this->policyStore(), $this->audit, $this->eventPublisher());
    }

    /** The script library, library runs, approvals and schedules for technicians (needs the edition's AccessPolicy). */
    public function scriptActions(): ScriptActions
    {
        return $this->scriptActions ??= new ScriptActions($this->devices(), $this->authorizer(), $this->settings(), $this->scripts(), $this->scriptRunner(), $this->bulkRunner(), $this->targets(),
            $this->approvals(), $this->schedules(), $this->approvedRunExecutor(), $this->audit, $this->eventPublisher());
    }

    /** Custom field definitions and values (needs the edition's AccessPolicy). */
    public function fieldActions(): FieldActions
    {
        return $this->fieldActions ??= new FieldActions($this->authorizer(), $this->customFields(), $this->devices(), $this->audit, $this->tenancy);
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
            $this->assets instanceof RmmAssetNamesInterface ? $this->assets : null, $this->clientLabel(),
            $this->policy === null ? null : $this->authorizer(), $this->tags(), $this->groups(), $this->metrics instanceof RmmMetricReaderInterface ? $this->metrics : null, $this->automationReader());
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
            $this->mesh(), $this->authorizer(), $this->bridge, $this->audit, $this->clientLabel(), $this->scriptActions());
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
        return new TechnicianApi($this->authorizer(), $this->technician(), $this->readModel(), $this->inventory(), $this->policyActions(), $this->scriptActions(), $this->fieldActions(), $this->alertingApi());
    }

    /** The alerts, maintenance windows, escalation policies and thresholds routes (mounted by {@see technicianApi()}). */
    public function alertingApi(): \RivetCore\Rmm\Http\AlertingApi
    {
        return $this->alertingApi ??= new \RivetCore\Rmm\Http\AlertingApi($this->alertingActions());
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
