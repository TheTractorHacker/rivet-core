<?php

declare(strict_types=1);

namespace RivetCore\Security;

/**
 * Thrown when a setting change would go below its floor, above its ceiling, or touch a locked control.
 *
 * @api
 */
final class BaselineViolation extends \DomainException
{
}
