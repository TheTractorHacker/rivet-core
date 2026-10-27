<?php

declare(strict_types=1);

namespace RivetCore\Panel;

/**
 * Who a panel request is, as derived from the device credential. The only source of client and contact in the whole API.
 *
 * @api
 */
final class PanelPrincipal
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $clientId,
        public readonly ?int $contactId,
        public readonly string $hostname,
    ) {
    }
}
