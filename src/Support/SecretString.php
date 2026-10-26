<?php

declare(strict_types=1);

namespace RivetCore\Support;

/**
 * Holds a secret so that dumping the object that holds it (print_r, var_dump, a cast to array, an error tracker walking properties, a
 * stack trace argument list) shows a marker instead of the value. Not a protection against code that can call reveal().
 *
 * @internal
 */
final class SecretString
{
    public function __construct(#[\SensitiveParameter] private string $value)
    {
    }

    public function reveal(): string
    {
        return $this->value;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['value' => $this->value === '' ? '(none)' : '(hidden)'];
    }

    /** @return array<string,mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A secret is not serializable.');
    }

    public function __toString(): string
    {
        return '(hidden)';
    }
}
