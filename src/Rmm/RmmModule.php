<?php

declare(strict_types=1);

namespace RivetCore\Rmm;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Checkin\CheckinService;
use RivetCore\Rmm\Checks\CheckEvaluator;
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
use RivetCore\Rmm\Installer\InstallerDownload;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Job\JobTypeRegistry;
use RivetCore\Rmm\Link\RmmLinker;
use RivetCore\Rmm\Maintenance\Housekeeping;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\NullRmmAudit;
use RivetCore\Rmm\Support\NullRmmMetricSink;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Update\UpdateService;

/**
 * The composition root: builds the module's services from the storage contract and the edition's adapters, once, lazily. The
 * edition's five-line REST bridges call {@see deviceApi()}; its cron entry calls {@see housekeeping()}.
 *
 * Options: `binary_dir` (where hosted agent binaries live, default none), `allow_insecure_http` (loopback test servers only),
 * `allow_linux` (admit the Linux test agent), `host_fallback`, `integration_name`, `installer_prefix`.
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

    private readonly RmmAuditInterface $audit;
    private readonly RmmMetricSinkInterface $metrics;

    /**
     * @param array{binary_dir?:?string,allow_insecure_http?:bool,allow_linux?:bool,host_fallback?:?string,integration_name?:string,installer_prefix?:string} $options
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
    ) {
        $this->audit = $audit ?? new NullRmmAudit();
        $this->metrics = $metrics ?? new NullRmmMetricSink();
    }

    public function sql(): Sql
    {
        return $this->sql ??= new Sql($this->database, $this->clock);
    }

    public function settings(): RmmSettings
    {
        return $this->settings ??= new RmmSettings($this->sql(), $this->box, $this->bridge, $this->options['integration_name'] ?? RmmProtocol::DEFAULT_INTEGRATION_NAME, $this->options['allow_insecure_http'] ?? false);
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
        return $this->enrollment ??= new EnrollmentService($this->sql(), $this->settings(), $this->assets, $this->tenancy, $this->linker(), $this->audit, $this->attempts(), $this->options['allow_linux'] ?? false);
    }

    public function deviceService(): DeviceService
    {
        return new DeviceService($this->sql(), $this->devices(), $this->settings(), $this->jobs(), $this->checks(), $this->linker(), $this->bridge, $this->assets, $this->tenancy, $this->audit, $this->enrollment());
    }

    public function checkin(): CheckinService
    {
        return new CheckinService($this->sql(), $this->settings(), $this->devices(), $this->checks(), $this->jobs(), $this->updates(), $this->linker(), $this->assets, $this->metrics);
    }

    public function installerDownload(): InstallerDownload
    {
        return new InstallerDownload($this->sql(), $this->settings(), $this->updates(), $this->tenancy, $this->audit, $this->attempts(), $this->options['installer_prefix'] ?? RmmProtocol::INSTALLER_NAME_PREFIX);
    }

    public function housekeeping(): Housekeeping
    {
        return new Housekeeping($this->sql(), $this->settings(), $this->bridge, $this->jobs());
    }

    /**
     * @param \Closure(string,int,int):bool $rateLimit (bucket, limit, windowSeconds): true when the call is within budget
     * @param bool $withModuleState answer 503 module_disabled when switched off (needs the module state given to the constructor)
     * @param (\Closure(int):void)|null $sleep long-poll pause
     * @param (\Closure():float)|null $now monotonic seconds for the long-poll deadline
     */
    public function deviceApi(\Closure $rateLimit, bool $withModuleState = true, ?\Closure $sleep = null, ?\Closure $now = null): DeviceApi
    {
        return new DeviceApi(
            $this->settings(), $this->devices(), $this->enrollment(), $this->checkin(), $this->jobs(), $this->updates(),
            $this->installerDownload(), $this->audit, $rateLimit, $this->options['allow_insecure_http'] ?? false,
            $withModuleState ? $this->state : null, $sleep, $now,
        );
    }

    /** Edition kill switch AND the master switch (a database read; the zero-database fast path is the state file of the module switch). */
    public function enabled(): bool
    {
        return ($this->state === null || $this->state->editionAllows()) && $this->settings()->enabled();
    }
}
