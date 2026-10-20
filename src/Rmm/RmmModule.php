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
use RivetCore\Rmm\Logs\LogActions;
use RivetCore\Rmm\Logs\LogRuleService;
use RivetCore\Rmm\Logs\LogService;
use RivetCore\Rmm\Maintenance\Housekeeping;
use RivetCore\Rmm\Mesh\MeshAgentService;
use RivetCore\Rmm\Mesh\MeshAgentSettings;
use RivetCore\Rmm\Mesh\MeshService;
use RivetCore\Rmm\Policy\EffectivePolicy;
use RivetCore\Rmm\Policy\PolicyStore;
use RivetCore\Rmm\Read\AutomationReader;
use RivetCore\Rmm\Patch\PatchActions;
use RivetCore\Rmm\Patch\PatchApprovalService;
use RivetCore\Rmm\Patch\PatchComplianceService;
use RivetCore\Rmm\Patch\PatchInstallService;
use RivetCore\Rmm\Patch\PatchPolicyStore;
use RivetCore\Rmm\Patch\PatchProtocol;
use RivetCore\Rmm\Deploy\DeployProtocol;
use RivetCore\Rmm\Contracts\ChangeWatchPolicyInterface;
use RivetCore\Rmm\Contracts\PackageSourcePolicyInterface;
use RivetCore\Rmm\Patch\PatchReader;
use RivetCore\Rmm\Patch\PatchScanService;
use RivetCore\Rmm\Patch\PatchScheduler;
use RivetCore\Rmm\Remote\RemoteActions;
use RivetCore\Rmm\Remote\RemoteSettings;
use RivetCore\Rmm\Remote\TransferService;
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
use RivetCore\Rmm\Capacity\DbTokenBucketStore;
use RivetCore\Rmm\Metrics\CompositeMetricSink;
use RivetCore\Rmm\Metrics\ExternalMetricSink;
use RivetCore\Rmm\Capacity\HerdControl;
use RivetCore\Rmm\Capacity\RedisTokenBucketStore;
use RivetCore\Rmm\Storage\MetricRollup;
use RivetCore\Rmm\Storage\PartitionMaintenance;
use RivetCore\Rmm\Storage\StorageMaintenance;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Rmm\Support\RmmCache;
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
use RivetCore\Rmm\Technician\StarterActions;
use RivetCore\Rmm\Starter\StarterInstaller;
use RivetCore\Rmm\Starter\StarterPack;
use RivetCore\Rmm\Http\StarterApi;
use RivetCore\Rmm\Technician\TechnicianActions;
use RivetCore\Rmm\Update\UpdateService;
use RivetCore\Webhooks\UrlPolicy;

/**
 * The composition root: builds the module's services from the storage contract and the edition's adapters, once, lazily. The
 * edition's five-line REST bridges call {@see deviceApi()}; its cron entry calls {@see housekeeping()}.
 *
 * Options: `binary_dir` (where hosted agent binaries live, default none), `transfer_dir` (where file transfers are stored, default `<binary_dir>/transfers`; without either, file transfer answers not_configured), `allow_insecure_http` (loopback test servers only),
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
    private ?StorageMaintenance $storage = null;
    private ?RmmCache $cache = null;
    private ?HerdControl $herd = null;
    private ?PartitionMaintenance $partitions = null;
    private ?RmmAuthorizer $authorizer = null;
    private ?RmmReadModel $readModel = null;
    private ?BinaryStore $binaryStore = null;
    private ?MeshService $mesh = null;
    private ?MeshAgentSettings $meshAgentSettings = null;
    private ?MeshAgentService $meshAgents = null;
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
    private ?StarterInstaller $starterInstaller = null;
    private ?StarterActions $starterActions = null;
    private ?StarterApi $starterApi = null;
    private ?\RivetCore\Rmm\Policy\StarterPack\PolicyPackInstaller $policyPackInstaller = null;
    private ?\RivetCore\Rmm\Technician\PolicyPackActions $policyPackActions = null;
    private ?ScheduleGateInterface $scheduleGate = null;
    private ?RemoteSettings $remoteSettings = null;
    private ?TransferService $transferService = null;
    private ?LogRuleService $logRuleService = null;
    private ?LogService $logService = null;
    private ?RemoteActions $remoteActions = null;
    private ?LogActions $logActions = null;
    private ?\RivetCore\Rmm\Http\RemoteApi $remoteApi = null;
    private ?PatchPolicyStore $patchPolicies = null;
    private ?PatchScanService $patchScans = null;
    private ?PatchInstallService $patchInstalls = null;
    private ?PatchApprovalService $patchApprovals = null;
    private ?PatchComplianceService $patchCompliance = null;
    private ?PatchReader $patchReader = null;
    private ?PatchActions $patchActions = null;
    private ?\RivetCore\Rmm\Http\PatchApi $patchApi = null;
    private ?PatchScheduler $patchScheduler = null;
    private ?PackageSourcePolicyInterface $packageSources = null;
    private ?\RivetCore\Rmm\Deploy\PackageCatalog $packageCatalog = null;
    private ?\RivetCore\Rmm\Deploy\DeployExpectations $deployExpectations = null;
    private ?\RivetCore\Rmm\Deploy\PackageParams $packageParams = null;
    private ?\RivetCore\Rmm\Deploy\HostedFiles $hostedFiles = null;
    private ?\RivetCore\Rmm\AutoInstall\AutoInstallService $autoInstall = null;
    private ?\RivetCore\Rmm\Deploy\DeployService $deployService = null;
    private ?\RivetCore\Rmm\Deploy\DeployApprovalService $deployApprovals = null;
    private ?\RivetCore\Rmm\Deploy\DeployReader $deployReader = null;
    private ?\RivetCore\Rmm\Deploy\DeployActions $deployActions = null;
    private ?\RivetCore\Rmm\Http\DeployApi $deployApi = null;
    private ?\RivetCore\Rmm\Deploy\DeployScheduler $deployScheduler = null;
    private ?ChangeWatchPolicyInterface $changeWatchPolicy = null;
    private ?\RivetCore\Rmm\Change\ChangeSettings $changeSettings = null;
    private ?\RivetCore\Rmm\Change\ChangeRuleService $changeRules = null;
    private ?\RivetCore\Rmm\Change\ChangeService $changeService = null;
    private ?\RivetCore\Rmm\Change\ChangeActions $changeActions = null;
    private ?\RivetCore\Rmm\Http\ChangeApi $changeApi = null;
    private ?\RivetCore\Rmm\Hypervisor\HypervisorSettings $hypervisorSettings = null;
    private ?\RivetCore\Rmm\Hypervisor\HypervisorAlerts $hypervisorAlerts = null;
    private ?\RivetCore\Rmm\Hypervisor\HypervisorService $hypervisorService = null;
    private ?\RivetCore\Rmm\Hypervisor\HypervisorActions $hypervisorActions = null;
    private ?\RivetCore\Rmm\Http\HypervisorApi $hypervisorApi = null;

    private readonly RmmAuditInterface $audit;
    private readonly RmmMetricSinkInterface $metrics;
    private readonly RmmEventsInterface $events;

    /**
     * @param array{binary_dir?:?string,transfer_dir?:?string,allow_insecure_http?:bool,allow_linux?:bool,host_fallback?:?string,integration_name?:string,installer_prefix?:string,max_upload_bytes?:?int,hosted_max_bytes?:?int,client_label?:string,denial_reasons?:array<string,string>,escalation?:RmmEscalationInterface,threshold_resolver?:ThresholdResolverInterface,cache?:\Psr\SimpleCache\CacheInterface,redis?:\RivetCore\Redis\RedisClientProviderInterface,metric_reader?:RmmMetricReaderInterface,starter_packs?:list<string>} $options
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
            // Scale S8: switching the module back on begins a warm start (when the `warm_start_s` limit is set).
            $this->settings->onEnable(function (): void {
                $this->herd()->startWarmStart();
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
        if ($this->jobs === null) {
            $this->jobs = new JobService($this->sql(), $this->settings(), $this->registry ?? JobTypeRegistry::withDefaults(), $this->eventPublisher(), $this->jobExtras(), $this->deviceState());
            // Phase 4: a finished patch job is applied as soon as its report arrives (the patch services are only built when one does).
            $this->jobs->onJobFinished(function (array $dev, string $jobId, string $type, string $state): void {
                if (PatchProtocol::isPatchJob($type) && $this->settings()->featureOn(PatchProtocol::FEATURE)) {
                    $this->patchJobFinished($dev, $jobId, $type);
                }
                // Phase 5: the same for a finished software job.
                if (DeployProtocol::isDeployJob($type) && $this->settings()->featureOn(DeployProtocol::FEATURE) && (int) ($this->settings()->get()['shed_level'] ?? 0) < 2) {
                    $row = $this->sql()->one('SELECT * FROM endpoint_agent_jobs WHERE job_id = ?', [$jobId]);
                    if ($row !== null) {
                        $this->deployService()->applyJob($dev, $row);
                    }
                }
            });
        }

        return $this->jobs;
    }

    /**
     * @param array<string,mixed> $dev
     */
    private function patchJobFinished(array $dev, string $jobId, string $type): void
    {
        if ((int) ($this->settings()->get()['shed_level'] ?? 0) >= 2) {
            return;   // the housekeeping pass applies it when the load drops
        }
        $row = $this->sql()->one('SELECT * FROM endpoint_agent_jobs WHERE job_id = ?', [$jobId]);
        if ($row === null) {
            return;
        }
        if ($type === PatchProtocol::JOB_SCAN) {
            $this->patchScans()->applyJob($dev, $row);
        } else {
            $this->patchInstalls()->applyJob($dev, $row);
        }
    }

    // ------------------------------------------------------------------ Phase 4: patch management

    /** Patch policies, their assignments and the rings. */
    public function patchPolicies(): PatchPolicyStore
    {
        return $this->patchPolicies ??= new PatchPolicyStore($this->sql(), $this->tenancy);
    }

    /** Queue a patch scan and apply what a device reports. */
    public function patchScans(): PatchScanService
    {
        return $this->patchScans ??= new PatchScanService($this->sql(), $this->jobs(), $this->devices(), $this->eventPublisher(), $this->patchApprovals(), $this->patchCompliance());
    }

    /** Queue a (gated) patch install and apply its result. */
    public function patchInstalls(): PatchInstallService
    {
        return $this->patchInstalls ??= new PatchInstallService($this->sql(), $this->settings(), $this->jobs(), $this->patchScans(), $this->patchPolicies(), $this->eventPublisher(), $this->patchCompliance());
    }

    /** Approve, decline, approval rules and automatic approval. */
    public function patchApprovals(): PatchApprovalService
    {
        return $this->patchApprovals ??= new PatchApprovalService($this->sql(), $this->patchPolicies(), $this->approvals(), $this->eventPublisher());
    }

    /** The `patch.compliance` and `patch.install_failed` checks. */
    public function patchCompliance(): PatchComplianceService
    {
        return $this->patchCompliance ??= new PatchComplianceService($this->sql(), $this->settings(), $this->checks());
    }

    /** Patch reads: one device, the fleet's compliance. */
    public function patchReader(): PatchReader
    {
        return $this->patchReader ??= new PatchReader($this->sql(), $this->settings(), $this->patchPolicies(), $this->tenancy);
    }

    /** What technicians do with patching (shared by the REST API and the edition's pages). */
    public function patchActions(): PatchActions
    {
        if ($this->patchActions === null) {
            $this->patchActions = new PatchActions($this->devices(), $this->authorizer(), $this->settings(), $this->patchScans(), $this->patchInstalls(), $this->patchApprovals(), $this->patchPolicies(), $this->patchReader(), $this->audit, $this->eventPublisher());
            $this->scriptActions()->setPatchActions($this->patchActions);
        }

        return $this->patchActions;
    }

    /** The patch routes (mounted by {@see technicianApi()}). */
    public function patchApi(): \RivetCore\Rmm\Http\PatchApi
    {
        return $this->patchApi ??= new \RivetCore\Rmm\Http\PatchApi($this->patchActions());
    }

    // ------------------------------------------------------------------ change monitoring

    /**
     * Replace the policy that decides what a custom watch (and a reported change) may point at (an edition may want its own rule). The default is
     * {@see \RivetCore\Rmm\Change\ChangeWatchPolicy}; set it before the first use of the module's change-monitoring services.
     */
    public function setChangeWatchPolicy(ChangeWatchPolicyInterface $policy): void
    {
        $this->changeWatchPolicy = $policy;
        $this->changeSettings = null;
        $this->changeService = null;
        $this->changeActions = null;
    }

    /** The change-monitoring settings and the signed configuration an agent receives. */
    public function changeSettings(): \RivetCore\Rmm\Change\ChangeSettings
    {
        return $this->changeSettings ??= new \RivetCore\Rmm\Change\ChangeSettings($this->sql(), $this->settings(), $this->changeWatchPolicy ??= new \RivetCore\Rmm\Change\ChangeWatchPolicy());
    }

    /** Change rules: a matching change raises an alert through the check evaluator. */
    public function changeRules(): \RivetCore\Rmm\Change\ChangeRuleService
    {
        return $this->changeRules ??= new \RivetCore\Rmm\Change\ChangeRuleService($this->sql(), $this->settings(), $this->checks(), $this->devices(), $this->eventPublisher());
    }

    /** Recorded changes: ingest, search, the timeline, retention. */
    public function changeService(): \RivetCore\Rmm\Change\ChangeService
    {
        return $this->changeService ??= new \RivetCore\Rmm\Change\ChangeService($this->sql(), $this->changeSettings(), $this->changeRules(), $this->eventPublisher());
    }

    /** Reading changes and managing change monitoring (shared by the REST API and the edition's pages). */
    public function changeActions(): \RivetCore\Rmm\Change\ChangeActions
    {
        return $this->changeActions ??= new \RivetCore\Rmm\Change\ChangeActions($this->devices(), $this->authorizer(), $this->settings(), $this->changeSettings(), $this->changeService(), $this->changeRules(), $this->audit);
    }

    // ------------------------------------------------------------------ hypervisor visibility (Proxmox VE, Hyper-V)

    /** The hypervisor limits (thresholds, intervals) and the signed configuration an agent receives. */
    public function hypervisorSettings(): \RivetCore\Rmm\Hypervisor\HypervisorSettings
    {
        return $this->hypervisorSettings ??= new \RivetCore\Rmm\Hypervisor\HypervisorSettings($this->settings());
    }

    /** Alerts and events from what a hypervisor host reported (through the check pipeline). */
    public function hypervisorAlerts(): \RivetCore\Rmm\Hypervisor\HypervisorAlerts
    {
        return $this->hypervisorAlerts ??= new \RivetCore\Rmm\Hypervisor\HypervisorAlerts($this->sql(), $this->settings(), $this->checks(), $this->eventPublisher());
    }

    /** Hypervisor ingest: validate a report and store hosts, VMs, storage and the state history; also the retention clean-up. */
    public function hypervisorService(): \RivetCore\Rmm\Hypervisor\HypervisorService
    {
        return $this->hypervisorService ??= new \RivetCore\Rmm\Hypervisor\HypervisorService($this->sql(), $this->settings(), $this->hypervisorAlerts());
    }

    /** The hypervisor read model: hosts with VM counts, one host, the VM search, one VM with its history. */
    public function hypervisorReader(): \RivetCore\Rmm\Hypervisor\HypervisorReader
    {
        return new \RivetCore\Rmm\Hypervisor\HypervisorReader($this->sql(), $this->settings());
    }

    /** Asset suggestions for a VM (hints only: the edition links, Core never auto-creates). */
    public function hypervisorLinker(): \RivetCore\Rmm\Hypervisor\HypervisorLinker
    {
        return new \RivetCore\Rmm\Hypervisor\HypervisorLinker($this->sql(), $this->assets);
    }

    /** Reading hypervisors and managing the monitoring (shared by the REST API and the edition's pages). */
    public function hypervisorActions(): \RivetCore\Rmm\Hypervisor\HypervisorActions
    {
        return $this->hypervisorActions ??= new \RivetCore\Rmm\Hypervisor\HypervisorActions($this->sql(), $this->devices(), $this->authorizer(), $this->settings(), $this->hypervisorSettings(), $this->hypervisorReader(),
            $this->hypervisorAlerts(), $this->hypervisorLinker(), $this->audit);
    }

    /** The hypervisor routes (mounted by {@see technicianApi()}). */
    public function hypervisorApi(): \RivetCore\Rmm\Http\HypervisorApi
    {
        return $this->hypervisorApi ??= new \RivetCore\Rmm\Http\HypervisorApi($this->hypervisorActions());
    }

    /** The change-monitoring routes (mounted by {@see technicianApi()}). */
    public function changeApi(): \RivetCore\Rmm\Http\ChangeApi
    {
        return $this->changeApi ??= new \RivetCore\Rmm\Http\ChangeApi($this->changeActions());
    }

    // ------------------------------------------------------------------ Phase 5: software deployment

    /**
     * Replace the policy that decides where an installer may be downloaded from (an edition may want its own rule). The default is the administrator's list of host
     * names ({@see \RivetCore\Rmm\Deploy\AllowedHostsPolicy}); set it before the first use of the module's deployment services.
     */
    public function setPackageSourcePolicy(PackageSourcePolicyInterface $policy): void
    {
        $this->packageSources = $policy;
        $this->packageCatalog = null;
        $this->deployService = null;
        $this->deployActions = null;
    }

    /** The package catalog, its versions and the allowed download hosts. */
    public function packageCatalog(): \RivetCore\Rmm\Deploy\PackageCatalog
    {
        if ($this->packageCatalog === null) {
            $sql = $this->sql();
            $this->packageSources ??= new \RivetCore\Rmm\Deploy\AllowedHostsPolicy(static function () use ($sql): array {
                return array_map(static fn (array $r): string => (string) $r['host'], $sql->all('SELECT host FROM rmm_software_hosts'));
            });
            $this->packageCatalog = new \RivetCore\Rmm\Deploy\PackageCatalog($sql, $this->packageSources);
        }

        return $this->packageCatalog;
    }

    /** What the assignments say each device should have. */
    public function deployExpectations(): \RivetCore\Rmm\Deploy\DeployExpectations
    {
        return $this->deployExpectations ??= new \RivetCore\Rmm\Deploy\DeployExpectations($this->sql(), $this->packageCatalog(), $this->patchPolicies());
    }

    /** Queue software jobs and apply their results. */
    public function deployService(): \RivetCore\Rmm\Deploy\DeployService
    {
        if ($this->deployService === null) {
            $this->deployService = new \RivetCore\Rmm\Deploy\DeployService($this->sql(), $this->settings(), $this->jobs(), $this->packageCatalog(), $this->deployExpectations(), $this->eventPublisher(), $this->packageParams(), $this->hostedFiles());
            // The result of an install job also settles the matching row of "install apps when the agent installs".
            $this->deployService->onInstallApplied(function (int $deviceId, int $packageId, array $parsed, string $mode, bool $lost): void {
                /** @var array{ok:bool,result:string,code:string,detail:string,version:string,reboot_required:bool} $parsed */
                $this->autoInstall()->onInstallResult($deviceId, $packageId, $parsed, $mode, $lost);
            });
        }

        return $this->deployService;
    }

    /** Vendor parameter values of packages, sealed with the edition's SecretBox (never returned by any read). */
    public function packageParams(): \RivetCore\Rmm\Deploy\PackageParams
    {
        return $this->packageParams ??= new \RivetCore\Rmm\Deploy\PackageParams($this->sql(), $this->box, $this->packageCatalog());
    }

    /** Installer files uploaded to the server (option `binary_dir`, subdirectory `hosted`; option `hosted_max_bytes` caps one file, default 512 MiB). */
    public function hostedFiles(): \RivetCore\Rmm\Deploy\HostedFiles
    {
        return $this->hostedFiles ??= new \RivetCore\Rmm\Deploy\HostedFiles($this->sql(), $this->updates(), $this->audit, $this->options['binary_dir'] ?? null, $this->options['hosted_max_bytes'] ?? null);
    }

    /**
     * The device-facing download of a hosted installer (`GET /api/v1/agent_software/{job_id}/{secret}`); the edition mounts it next to the other device routes.
     *
     * @param \Closure(string,int,int):bool $rateLimit (bucket, limit, windowSeconds): true when within budget
     */
    public function hostedFileApi(\Closure $rateLimit): \RivetCore\Rmm\Http\HostedFileApi
    {
        return new \RivetCore\Rmm\Http\HostedFileApi($this->hostedFiles(), $this->settings(), $rateLimit, $this->options['allow_insecure_http'] ?? false);
    }

    /** Two-person approval of software runs and assignments. */
    public function deployApprovals(): \RivetCore\Rmm\Deploy\DeployApprovalService
    {
        return $this->deployApprovals ??= new \RivetCore\Rmm\Deploy\DeployApprovalService($this->sql(), $this->approvals(), $this->deployService(), $this->deployExpectations(), $this->devices(), $this->packageCatalog());
    }

    /** Deployment reads: one device, the fleet report. */
    public function deployReader(): \RivetCore\Rmm\Deploy\DeployReader
    {
        return $this->deployReader ??= new \RivetCore\Rmm\Deploy\DeployReader($this->sql(), $this->settings());
    }

    /** What technicians do with software deployment (shared by the REST API and the edition's pages). */
    public function deployActions(): \RivetCore\Rmm\Deploy\DeployActions
    {
        if ($this->deployActions === null) {
            $this->deployActions = new \RivetCore\Rmm\Deploy\DeployActions($this->devices(), $this->authorizer(), $this->settings(), $this->sql(), $this->packageCatalog(), $this->deployService(), $this->deployExpectations(),
                $this->deployApprovals(), $this->deployReader(), $this->audit, $this->tenancy);
            $this->scriptActions()->setDeployActions($this->deployActions);
        }

        return $this->deployActions;
    }

    /** The software routes (mounted by {@see technicianApi()}). */
    public function deployApi(): \RivetCore\Rmm\Http\DeployApi
    {
        return $this->deployApi ??= new \RivetCore\Rmm\Http\DeployApi($this->deployActions());
    }

    /** The software work of the housekeeping pass. */
    public function deployScheduler(): \RivetCore\Rmm\Deploy\DeployScheduler
    {
        return $this->deployScheduler ??= new \RivetCore\Rmm\Deploy\DeployScheduler($this->sql(), $this->settings(), $this->devices(), $this->deployService(), $this->deployExpectations(),
            $this->scheduleGate ?? new MaintenanceScheduleGate($this->maintenance(), $this->settings()), $this->autoInstall(), $this->hostedFiles());
    }

    /** What technicians and administrators do with auto-install (shared by the REST API and the edition's pages). */
    public function autoInstallActions(): \RivetCore\Rmm\AutoInstall\AutoInstallActions
    {
        return new \RivetCore\Rmm\AutoInstall\AutoInstallActions($this->devices(), $this->authorizer(), $this->settings(), $this->packageCatalog(), $this->packageParams(), $this->hostedFiles(), $this->autoInstall(), $this->audit);
    }

    public function autoInstallApi(): \RivetCore\Rmm\Http\AutoInstallApi
    {
        return new \RivetCore\Rmm\Http\AutoInstallApi($this->autoInstallActions(), $this->hostedFiles()->maxBytes());
    }

    /** Install apps when the agent installs: auto-install policies, installer-time selection, planning at enrollment, the per-device app status. */
    public function autoInstall(): \RivetCore\Rmm\AutoInstall\AutoInstallService
    {
        if ($this->autoInstall === null) {
            $this->autoInstall = new \RivetCore\Rmm\AutoInstall\AutoInstallService($this->sql(), $this->settings(), $this->deployService(), $this->packageCatalog(), $this->deployExpectations(), $this->packageParams(),
                $this->deployApprovals(), $this->tenancy, $this->audit);
        }

        return $this->autoInstall;
    }

    /** The patch work of the housekeeping pass. */
    public function patchScheduler(): PatchScheduler
    {
        return $this->patchScheduler ??= new PatchScheduler($this->sql(), $this->settings(), $this->devices(), $this->patchScans(), $this->patchInstalls(), $this->patchApprovals(), $this->patchCompliance(), $this->patchPolicies(), $this->maintenance(),
            $this->scheduleGate ?? new MaintenanceScheduleGate($this->maintenance(), $this->settings()));
    }

    public function checks(): CheckEvaluator
    {
        return $this->checks ??= new CheckEvaluator($this->sql(), $this->settings(), $this->bridge, $this->devices(), $this->eventPublisher(), $this->alertingEngine());
    }

    // ------------------------------------------------------------------ Phases 6 and 8: remote tools and log collection

    /** The remote-tools row: allowed transfer folders, log sources and the caps on both. */
    public function remoteSettings(): RemoteSettings
    {
        return $this->remoteSettings ??= new RemoteSettings($this->sql());
    }

    /**
     * File transfer between devices and the server. Needs a storage folder: option `transfer_dir`, else `<binary_dir>/transfers`; with neither, transfers answer
     * 409 `not_configured` and nothing is stored.
     */
    public function transfers(): TransferService
    {
        $dir = $this->options['transfer_dir'] ?? (isset($this->options['binary_dir']) && $this->options['binary_dir'] !== '' ? rtrim($this->options['binary_dir'], '/\\') . '/transfers' : null);

        return $this->transferService ??= new TransferService($this->sql(), $this->remoteSettings(), $this->jobs(), $dir, $this->audit, $this->eventPublisher());
    }

    /** Log rules: a match raises an alert through the check evaluator (and so through the Phase 3 alerting engine). */
    public function logRules(): LogRuleService
    {
        return $this->logRuleService ??= new LogRuleService($this->sql(), $this->settings(), $this->checks(), $this->devices(), $this->eventPublisher());
    }

    /** Collected logs: ingest, search, retention. */
    public function logService(): LogService
    {
        return $this->logService ??= new LogService($this->sql(), $this->remoteSettings(), $this->logRules());
    }

    /** Service and process managers, file transfer, Mesh view information and the remote-tools settings (shared by the REST API and the edition's pages). */
    public function remoteActions(): RemoteActions
    {
        return $this->remoteActions ??= new RemoteActions($this->sql(), $this->devices(), $this->authorizer(), $this->settings(), $this->jobs(), $this->transfers(), $this->remoteSettings(), $this->mesh(), $this->audit, $this->meshAgentSettings(), $this->meshAgents(), $this->targets());
    }

    /** Reading collected logs and managing log rules. */
    public function logActions(): LogActions
    {
        return $this->logActions ??= new LogActions($this->devices(), $this->authorizer(), $this->logService(), $this->logRules(), $this->audit);
    }

    /** The remote-tools and log routes (mounted by {@see technicianApi()}). */
    public function remoteApi(): \RivetCore\Rmm\Http\RemoteApi
    {
        return $this->remoteApi ??= new \RivetCore\Rmm\Http\RemoteApi($this->remoteActions(), $this->logActions(), $this->technician());
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
            $this->storm(), $this->checkEval(), $this->settings(), $this->audit, $this->effectivePolicy());
    }

    public function linker(): RmmLinker
    {
        return $this->linker ??= new RmmLinker($this->sql(), $this->settings(), $this->bridge, $this->assets);
    }

    public function updates(): UpdateService
    {
        return $this->updates ??= new UpdateService($this->sql(), $this->settings(), $this->audit, $this->options['binary_dir'] ?? null, $this->options['allow_insecure_http'] ?? false, $this->options['host_fallback'] ?? null, $this->cache());
    }

    public function attempts(): AttemptLog
    {
        return $this->attempts ??= new AttemptLog($this->sql());
    }

    public function enrollment(): EnrollmentService
    {
        if ($this->enrollment === null) {
            $this->enrollment = new EnrollmentService($this->sql(), $this->settings(), $this->assets, $this->tenancy, $this->linker(), $this->audit, $this->attempts(), $this->options['allow_linux'] ?? false, $this->clientLabel(), $this->eventPublisher(), $this->cache(), $this->herd());
            // Install apps when the agent installs: plan the catalog apps of a device that has just enrolled (a no-op while `software_deploy` is off).
            $this->enrollment->onEnrolled(function (int $deviceId, int $tokenId, string $event): void {
                if ($this->settings()->featureOn(DeployProtocol::FEATURE) && ($dev = $this->devices()->find($deviceId)) !== null) {
                    $this->autoInstall()->planDevice($dev, $tokenId, $event);
                }
            });
        }

        return $this->enrollment;
    }

    public function deviceService(): DeviceService
    {
        return new DeviceService($this->sql(), $this->devices(), $this->settings(), $this->jobs(), $this->checks(), $this->linker(), $this->bridge, $this->assets, $this->tenancy, $this->audit, $this->enrollment(), $this->cache());
    }

    public function checkin(): CheckinService
    {
        return new CheckinService($this->sql(), $this->settings(), $this->devices(), $this->checks(), $this->jobs(), $this->updates(), $this->linker(), $this->assets, $this->metrics,
            function (array $work): void {
                $this->ingestQueue()->enqueue($work);
            }, $this->deviceState(), $this->software(), $this->eventPublisher(), $this->effectivePolicy(), $this->remoteSettings(), $this->meshAgents(), $this->changeSettings(), $this->hypervisorSettings(), $this->hypervisorService());
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
        return $this->capacity ??= new CapacityReport($this->sql(), $this->settings(), $this->ingestQueue(), $this->state(), $this->shedder(), $this->cache(), $this->herd(), $this->externalSink());
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
        return new InstallerDownload($this->sql(), $this->settings(), $this->updates(), $this->tenancy, $this->audit, $this->attempts(), $this->options['installer_prefix'] ?? RmmProtocol::INSTALLER_NAME_PREFIX, $this->clientLabel(), $this->meshAgents(), $this->binaryStore());
    }

    /**
     * One-off (idempotent) cleanup of checks that no longer apply to a device, for every live device or just one: removes the
     * `endpoint_agent_checks` row of a check key the device would not be given now (other OS, off the schedule, removed by a policy) and resolves
     * its open alert as "no longer applies" (system, no ticket). The check-in does the same lazily for a device that checks in; this reaches the
     * devices that are offline and is what to run once after upgrading to 1.0.0-rc.24. Server-reported keys (log, change, patch rules) are kept.
     *
     * @return array{devices:int,removed:int}
     */
    public function reconcileChecks(?int $deviceId = null): array
    {
        $rec = new \RivetCore\Rmm\Checks\CheckReconciler($this->sql(), $this->checks());
        $policies = $this->settings()->featureOn('policies');
        $n = 0;
        $removed = 0;
        $rows = $deviceId !== null
            ? array_filter([$this->sql()->one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId])])
            : $this->sql()->all('SELECT * FROM endpoint_agent_devices WHERE retired_at IS NULL AND revoked_at IS NULL');
        foreach ($rows as $dev) {
            $caps = DeviceState::capabilitiesOf($this->deviceState()->get((int) $dev['device_id']));
            if ($policies) {
                $list = $this->effectivePolicy()->forDevice($dev, $caps)['checks'];
            } else {
                $os = \RivetCore\Rmm\Checks\RmmChecks::deviceOs($dev);
                $list = $os === null ? $this->settings()->checks() : \RivetCore\Rmm\Checks\RmmChecks::forOs($this->settings()->checks(), $os);
                $offerCaps = $this->settings()->hasExtendedChecks() ? $caps : null;
                $list = array_values(array_filter($list, static fn (array $c): bool => \RivetCore\Rmm\Checks\CheckCatalog::offeredTo((string) $c['type'], $offerCaps)));
            }
            $removed += $rec->reconcile($dev, array_map(static fn (array $c): string => (string) $c['key'], $list), [], true);
            ++$n;
        }

        return ['devices' => $n, 'removed' => $removed];
    }

    public function housekeeping(): Housekeeping
    {
        return new Housekeeping($this->sql(), $this->settings(), $this->bridge, $this->jobs(), null, $this->shedder(), $this->ingestQueue(), $this->eventPublisher(), $this->deviceState(),
            $this->databaseSink(), $this->scheduleRunner(), $this->approvals(), $this->approvedRunExecutor(), $this->jobExtras(), $this->policyStore(),
            new AlertingHousekeeping($this->maintenance(), $this->escalation(), $this->alerts(), $this->storm(), $this->checkEval()), $this->storage(), $this->cache(), $this->externalSink(),
            $this->logService(), $this->logRules(), $this->transfers(), $this->patchScheduler(), $this->deployScheduler(), $this->changeService(), $this->changeRules(), $this->hypervisorService());
    }

    /** The database metric sink, whether it was passed as the sink or inside a {@see CompositeMetricSink}. */
    private function databaseSink(): ?DatabaseMetricSink
    {
        if ($this->metrics instanceof DatabaseMetricSink) {
            return $this->metrics;
        }

        return $this->metrics instanceof CompositeMetricSink ? $this->metrics->find(DatabaseMetricSink::class) : null;
    }

    /** The buffer in front of an external metric store (scaling item S4), whether it was passed as the sink or inside a {@see CompositeMetricSink}. */
    private function externalSink(): ?ExternalMetricSink
    {
        if ($this->metrics instanceof ExternalMetricSink) {
            return $this->metrics;
        }

        return $this->metrics instanceof CompositeMetricSink ? $this->metrics->find(ExternalMetricSink::class) : null;
    }

    /**
     * What answers the pages' history questions: the `metric_reader` option (for example an {@see \RivetCore\Rmm\Metrics\ExternalMetricReader}), else the sink when it
     * is a reader, else the database sink inside a composite.
     */
    private function metricReader(): ?RmmMetricReaderInterface
    {
        $given = $this->options['metric_reader'] ?? null;
        if ($given !== null) {
            return $given;
        }
        if ($this->metrics instanceof RmmMetricReaderInterface) {
            return $this->metrics;
        }

        return $this->databaseSink();
    }

    /**
     * Scale S5: the daily metric rollup (only with a {@see DatabaseMetricSink}) and the time partitions of the history tables. Called by {@see housekeeping()};
     * also the entry point for an administrator's explicit probe or conversion (`partitions()->probe()`, `partitions()->convert()`).
     */
    public function storage(): StorageMaintenance
    {
        return $this->storage ??= new StorageMaintenance($this->partitions(), $this->databaseSink() !== null ? new MetricRollup($this->sql()) : null);
    }

    /**
     * The read-path cache (scaling item S8): fleet counters, the capacity panel, the release list. Pass a PSR-16 cache as the `cache` option to share it across web nodes
     * (Core's {@see \RivetCore\Mcp\RedisMetadataCache} is one); without it the `rmm_cache` table is used.
     */
    public function cache(): RmmCache
    {
        return $this->cache ??= new RmmCache($this->sql(), $this->options['cache'] ?? null);
    }

    /**
     * Warm start and the per-client token buckets. The buckets use Redis when the edition passes a `redis` option (a RedisClientProviderInterface), otherwise the
     * `rmm_token_buckets` table.
     */
    public function herd(): HerdControl
    {
        if ($this->herd === null) {
            $redis = $this->options['redis'] ?? null;
            $this->herd = new HerdControl($this->sql(), $this->settings(), $this->state(), $redis !== null ? new RedisTokenBucketStore($redis) : new DbTokenBucketStore($this->sql()));
        }

        return $this->herd;
    }

    /** Capability probe, conversion and maintenance of the time partitions (docs/rmm/STORAGE.md). */
    public function partitions(): PartitionMaintenance
    {
        return $this->partitions ??= new PartitionMaintenance($this->sql());
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

    /**
     * The starter pack installer: the bundled pack plus any pack folders listed in the `starter_packs` option (see docs/rmm/STARTER_PACK.md). No tables of
     * its own; it installs through {@see scripts()} and {@see policyStore()}.
     */
    public function starterInstaller(): StarterInstaller
    {
        if ($this->starterInstaller === null) {
            $packs = [StarterPack::bundled()];
            foreach ((array) ($this->options['starter_packs'] ?? []) as $dir) {
                if ($dir !== '') {
                    $packs[] = StarterPack::fromDirectory($dir);
                }
            }
            $this->starterInstaller = new StarterInstaller($this->sql(), $this->scripts(), $this->policyStore(), $this->settings(), $packs);
        }

        return $this->starterInstaller;
    }

    /** Browse, preview, install and remove starter pack content (administrators; needs the edition's AccessPolicy). */
    public function starterActions(): StarterActions
    {
        return $this->starterActions ??= new StarterActions($this->authorizer(), $this->starterInstaller(), $this->audit, $this->eventPublisher());
    }

    /** The policy starter pack (checks, alerting presets, patch policies, maintenance windows, change presets; administrators). */
    public function policyPackInstaller(): \RivetCore\Rmm\Policy\StarterPack\PolicyPackInstaller
    {
        return $this->policyPackInstaller ??= new \RivetCore\Rmm\Policy\StarterPack\PolicyPackInstaller($this->policyStore(), $this->patchPolicies(), $this->maintenance(), $this->changeSettings());
    }

    public function policyPackActions(): \RivetCore\Rmm\Technician\PolicyPackActions
    {
        return $this->policyPackActions ??= new \RivetCore\Rmm\Technician\PolicyPackActions($this->authorizer(), $this->policyPackInstaller(), $this->audit);
    }

    /** The starter pack routes (mounted by {@see technicianApi()}). */
    public function starterApi(): StarterApi
    {
        return $this->starterApi ??= new StarterApi($this->starterActions(), $this->policyPackActions());
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
            $this->policy === null ? null : $this->authorizer(), $this->tags(), $this->groups(), $this->metricReader(), $this->automationReader(), $this->cache(), $this->logService(), $this->transfers(), $this->meshAgents(), $this->patchReader(), $this->deployReader(), $this->changeService());
    }

    /** Hosted agent binaries: validate, store, publish, serve. */
    public function binaryStore(): BinaryStore
    {
        return $this->binaryStore ??= new BinaryStore($this->sql(), $this->updates(), $this->audit, $this->options['binary_dir'] ?? null, $this->options['max_upload_bytes'] ?? null);
    }

    /** MeshCentral remote access (URL checks, probe through the UrlPolicy, launch). */
    public function mesh(): MeshService
    {
        return $this->mesh ??= new MeshService($this->sql(), $this->settings(), $this->devices(), $this->box, $this->urlPolicy ?? new UrlPolicy(false), $this->options['allow_insecure_http'] ?? false, $this->meshAgentSettings());
    }

    /** The install settings of the optional MeshCentral agent (device group, certificate pin, overwrite switch, table corrections). */
    public function meshAgentSettings(): MeshAgentSettings
    {
        return $this->meshAgentSettings ??= new MeshAgentSettings($this->sql(), $this->settings());
    }

    /** The optional MeshCentral agent: the installer's Mesh block, the device's report and the automatic node-id mapping. */
    public function meshAgents(): MeshAgentService
    {
        return $this->meshAgents ??= new MeshAgentService($this->sql(), $this->settings(), $this->meshAgentSettings(), $this->devices(), $this->audit, $this->eventPublisher());
    }

    /** Per-client installers and deployment snippets (the administrator side). */
    public function installerService(): InstallerService
    {
        return $this->installerService ??= new InstallerService($this->sql(), $this->updates(), $this->installerDownload(), $this->enrollment(), $this->tenancy, $this->audit, $this->binaryStore(), $this->clientLabel(), $this->meshAgents(), $this->autoInstall());
    }

    /** Technician and administrator actions on devices, shared by the web handlers and the REST API. */
    public function technician(): TechnicianActions
    {
        return $this->technician ??= new TechnicianActions($this->sql(), $this->devices(), $this->deviceService(), $this->enrollment(), $this->jobs(), $this->updates(),
            $this->mesh(), $this->authorizer(), $this->bridge, $this->audit, $this->clientLabel(), $this->scriptActions(), $this->settings());
    }

    /** The validated administration operations (settings, MeshCentral, signing key, binaries, releases, installers). */
    public function admin(): RmmAdmin
    {
        return $this->admin ??= new RmmAdmin($this->sql(), $this->settings(), $this->box, $this->authorizer(), $this->binaryStore(), $this->updates(), $this->mesh(),
            $this->installerService(), $this->devices(), $this->audit, $this->meshAgentSettings());
    }

    /** The technician REST API handler (`endpoint_devices`): the edition authenticates and passes the principal. */
    public function technicianApi(): TechnicianApi
    {
        return new TechnicianApi($this->authorizer(), $this->technician(), $this->readModel(), $this->inventory(), $this->policyActions(), $this->scriptActions(), $this->fieldActions(), $this->alertingApi(), $this->remoteApi(), $this->starterApi(), $this->patchApi(), $this->deployApi(), $this->changeApi(), $this->autoInstallApi(), $this->hypervisorApi());
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
            $disabledAnswer ?? ($withModuleState ? DeviceApi::DISABLED_UNIFORM : DeviceApi::DISABLED_COMPAT), $this->state(), $this->herd(), $this->transfers(), $this->logService(), $this->changeService(),
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
