<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * One extra input a destination preset needs besides the URL (an ntfy topic, a Telegram chat id, a Matrix room id).
 * target says where the value goes: "url" replaces {name} in Destination::$urlHint (see Destination::buildUrl()),
 * "option" is passed to PayloadFormatter as the option named by $option.
 *
 * @api
 */
final readonly class DestinationField
{
    /** @param 'text'|'number'|'secret' $type @param 'url'|'option' $target */
    public function __construct(
        public string $name,
        public string $label,
        public string $type,
        public bool $required,
        public string $help,
        public string $target = 'option',
        public string $option = '',
        public string $example = '',
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
