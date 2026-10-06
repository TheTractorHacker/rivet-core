<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * One subscribable event, as listed by EventCatalog. Pure data for an event picker (name, one-line description,
 * severity, the payload fields a receiver can expect, search synonyms).
 *
 * @api
 */
final readonly class EventDefinition
{
    /**
     * @param 'info'|'warning'|'critical' $severity
     * @param list<array{path:string,type:string,description:string}> $payloadFields
     * @param string|null $since null = emitted by the editions today; 'planned' = reserved/likely, not emitted everywhere yet
     * @param list<string> $tags search synonyms
     */
    public function __construct(
        public string $id,
        public string $group,
        public string $groupLabel,
        public string $label,
        public string $description,
        public string $severity,
        public array $payloadFields,
        public ?string $since,
        public array $tags,
    ) {
    }

    /** @return array{id:string,group:string,groupLabel:string,label:string,description:string,severity:string,payloadFields:list<array{path:string,type:string,description:string}>,since:?string,tags:list<string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'group' => $this->group, 'groupLabel' => $this->groupLabel, 'label' => $this->label,
            'description' => $this->description, 'severity' => $this->severity, 'payloadFields' => $this->payloadFields,
            'since' => $this->since, 'tags' => $this->tags,
        ];
    }
}
