<?php

declare(strict_types=1);

namespace RivetCore\KB;

/**
 * Thrown by PdfConverter when an upload is malformed, protected, oversized, or
 * has nothing importable in it.
 *
 * Same contract as DocxConversionException: the message is written to be shown
 * to the person who uploaded the file. It never contains a filesystem path, a
 * poppler stderr dump, or anything else that would leak server internals, so a
 * caller can put it straight into flash_alert(). Detail for an administrator
 * goes to error_log() at the throw site instead.
 */
class PdfConversionException extends \RuntimeException
{
}
