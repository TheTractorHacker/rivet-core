<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * The state a monitoring source reports for one check, normalised. The integer values are Uptime Kuma's heartbeat statuses.
 *
 * @api
 */
enum MonitorStatus: int
{
    case Down = 0;
    case Up = 1;
    case Pending = 2;
    case Maintenance = 3;
}
