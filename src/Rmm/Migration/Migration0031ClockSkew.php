<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Device clock skew: what the server measured (device collected_at minus server time), what the agent estimated about itself, why the last check-in was
 * refused and when the once-a-day `rmm.device.clock_skew` event last fired. Four nullable columns and one text column on `endpoint_agent_devices`;
 * purely additive and idempotent (each ADD COLUMN is guarded), nothing is backfilled.
 *
 * @internal
 */
final class Migration0031ClockSkew implements MigrationInterface
{
    private const COLUMNS = [
        'clock_skew_s' => 'int(11) DEFAULT NULL',
        'clock_skew_at' => 'datetime DEFAULT NULL',
        'clock_offset_s' => 'int(11) DEFAULT NULL',
        'clock_skew_event_at' => 'datetime DEFAULT NULL',
        'last_reject_reason' => 'varchar(200) DEFAULT NULL',
        'last_reject_at' => 'datetime DEFAULT NULL',
    ];

    public function id(): string
    {
        return '0031_rmm_clock_skew';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (self::COLUMNS as $name => $def) {
            if (!Migration0015EndpointAgentConverge::hasColumn($database, 'endpoint_agent_devices', $name)) {
                $database->execute("ALTER TABLE `endpoint_agent_devices` ADD COLUMN `$name` $def");
            }
        }
    }
}
