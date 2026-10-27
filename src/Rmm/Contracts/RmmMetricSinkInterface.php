<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * Optional: where the metric samples of a check-in go. RivetIT feeds its Metrics subsystem; RivetMSP has none and uses
 * {@see \RivetCore\Rmm\Support\NullRmmMetricSink}.
 *
 * @api
 */
interface RmmMetricSinkInterface
{
    /**
     * @param list<array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string}> $samples
     *        key is a metric registry key (cpu.utilization, memory.utilization, disk.utilization, network.rx_bytes_per_s,
     *        network.tx_bytes_per_s, system.uptime_seconds, system.pending_reboot, memory.total_bytes, disk.total_bytes, disk.free_bytes).
     *        Core never sends a null or zero-for-missing value; the sink drops out-of-range values (never clamps).
     */
    public function ingest(array $samples, int $integrationId): void;
}
