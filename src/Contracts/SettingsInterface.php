<?php

declare(strict_types=1);

namespace RivetCore\Contracts;

/** Read-only view of an edition's settings. Core never queries settings tables itself.
 *
 * @api
 */
interface SettingsInterface
{
    public function get(string $key, mixed $default = null): mixed;
}
