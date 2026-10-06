<?php

declare(strict_types=1);

namespace RivetCore\KB;

/**
 * Thrown by DocxConverter when an upload is malformed, oversized, or hostile.
 *
 * The message is written to be shown to the person who uploaded the file - it
 * never contains a filesystem path, a stack detail, or anything else that would
 * leak server internals - so a caller can put it straight into flash_alert().
 */
class DocxConversionException extends \RuntimeException
{
}
