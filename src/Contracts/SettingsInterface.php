<?php

declare(strict_types=1);

namespace RivetCore\Contracts;

/** Read-only view of an edition's settings. Core never queries settings tables itself.
 *
 * Contract (checked by Testing\SettingsConformanceTestCase): a key that is not stored returns $default unchanged (same value
 * and type, no coercion); a stored value is returned as stored even when falsy ('0', '', 0, false), never replaced by
 * $default; get() never throws, whatever the key (a backend failure reads as "not stored"); the same key reads the same value
 * twice. Keys are opaque strings, a SQL-backed edition returns scalars as strings.
 *
 * @api
 */
interface SettingsInterface
{
    public function get(string $key, mixed $default = null): mixed;
}
