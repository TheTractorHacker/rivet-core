<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Profile;

/**
 * A profile (or an import file) was refused. Every message is plain text written for the person who made the mistake and never contains the rejected
 * value or a path, so it is safe to show. {@see errors()} lists all of them (at most {@see MAX_ERRORS}); getMessage() is the first.
 *
 * @api
 */
final class ProfileValidationException extends \InvalidArgumentException
{
    public const MAX_ERRORS = 20;

    /** @var list<string> */
    private array $errors;

    /** @param list<string> $errors */
    public function __construct(array $errors)
    {
        $this->errors = array_slice(array_values($errors === [] ? ['The profile is not valid.'] : $errors), 0, self::MAX_ERRORS);
        parent::__construct($this->errors[0]);
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
