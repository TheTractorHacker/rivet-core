<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * The HOT RMM stores of one installation, chosen by the `rmm_data_store` setting (see {@see RmmDataStoreFactory}). Core's services take their store from here;
 * an edition that reads RMM history directly (today: a few admin pages) takes it from {@see \RivetCore\Rmm\RmmModule::dataStores()}.
 *
 * `metrics()` is null when the edition keeps its metrics elsewhere (RivetIT feeds its own Metrics subsystem); the metric tiers of this object exist only when
 * the module was given a {@see MetricStore} as its sink.
 *
 * @api
 */
final class RmmDataStores
{
    /** @var string|\Closure(): string */
    private readonly string|\Closure $kind;

    /**
     * @param string|\Closure(): string $kind the kind, or a closure that answers it on first use
     */
    public function __construct(
        string|\Closure $kind,
        private readonly CheckHistoryStore $checkHistory,
        private readonly LogStore $logs,
        private readonly ChangeEventStore $changes,
        private readonly SoftwareHistoryStore $softwareHistory,
        private readonly PatchHistoryStore $patchHistory,
        private readonly DeploymentHistoryStore $deploymentHistory,
        private readonly HypervisorHistoryStore $hypervisorHistory,
        private readonly StoreMaintenance $maintenance,
        private readonly ?MetricStore $metrics = null,
    ) {
        $this->kind = $kind;
    }

    /**
     * Stores that pick the real implementation on first use. The module builds its services (and so these) without touching the database, because an off module
     * answers a check-in from its state file with zero queries; the setting is read when the first store is used.
     *
     * @param \Closure(): RmmDataStores $resolve builds the real stores (called once by the caller's own memo; this object calls it on every use, so memoize it)
     */
    public static function lazy(\Closure $resolve, ?MetricStore $metrics = null): self
    {
        return new self(
            static fn (): string => $resolve()->kind(),
            new LazyCheckHistoryStore($resolve),
            new LazyLogStore($resolve),
            new LazyChangeEventStore($resolve),
            new LazySoftwareHistoryStore($resolve),
            new LazyPatchHistoryStore($resolve),
            new LazyDeploymentHistoryStore($resolve),
            new LazyHypervisorHistoryStore($resolve),
            new LazyStoreMaintenance($resolve),
            $metrics,
        );
    }

    /** One of {@see RmmDataStoreKind::ALL}. */
    public function kind(): string
    {
        return $this->kind instanceof \Closure ? ($this->kind)() : $this->kind;
    }

    public function checkHistory(): CheckHistoryStore
    {
        return $this->checkHistory;
    }

    public function logs(): LogStore
    {
        return $this->logs;
    }

    public function changes(): ChangeEventStore
    {
        return $this->changes;
    }

    public function softwareHistory(): SoftwareHistoryStore
    {
        return $this->softwareHistory;
    }

    public function patchHistory(): PatchHistoryStore
    {
        return $this->patchHistory;
    }

    public function deploymentHistory(): DeploymentHistoryStore
    {
        return $this->deploymentHistory;
    }

    public function hypervisorHistory(): HypervisorHistoryStore
    {
        return $this->hypervisorHistory;
    }

    public function maintenance(): StoreMaintenance
    {
        return $this->maintenance;
    }

    public function metrics(): ?MetricStore
    {
        return $this->metrics;
    }
}
