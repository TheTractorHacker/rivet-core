<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Profile;

/**
 * One requirement of a profile: a type, optionally a data class, a number (days, hours or 1 for a yes/no requirement) and an optional note ("contract clause 14.2").
 * Immutable and always valid: the constructor refuses what the type does not allow.
 *
 * @api
 */
final readonly class Requirement
{
    public const NOTE_MAX = 300;

    public function __construct(public string $type, public ?string $dataClass, public int $value, public ?string $note = null)
    {
        if (!RequirementType::isValid($type)) {
            throw new \InvalidArgumentException('Unknown requirement type.');
        }
        if (RequirementType::takesClass($type)) {
            if ($dataClass === null || !RequirementType::allowsClass($type, $dataClass)) {
                throw new \InvalidArgumentException('Requirement "' . RequirementType::label($type) . '" does not apply to that kind of data.');
            }
        } elseif ($dataClass !== null) {
            throw new \InvalidArgumentException('Requirement "' . RequirementType::label($type) . '" does not take a kind of data.');
        }
        $meta = RequirementType::ALL[$type];
        if ($value < $meta['min'] || $value > $meta['max']) {
            throw new \InvalidArgumentException('Requirement "' . RequirementType::label($type) . '" must be from ' . $meta['min'] . ' to ' . $meta['max'] . ' ' . ($meta['unit'] === 'flag' ? '(yes/no)' : $meta['unit']) . '.');
        }
        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw new \InvalidArgumentException('A requirement note may have at most ' . self::NOTE_MAX . ' characters.');
        }
    }

    /** Stable identity inside a profile: one requirement per type and data class. */
    public function key(): string
    {
        return $this->type . '|' . ($this->dataClass ?? '');
    }

    /** A short human sentence, for example "Minimum retention: Audit events, 2555 days". */
    public function describe(): string
    {
        $unit = RequirementType::unit($this->type);
        $what = RequirementType::label($this->type) . ($this->dataClass !== null ? ' (' . DataClass::label($this->dataClass) . ')' : '');

        return $unit === RequirementType::UNIT_FLAG ? $what : $what . ': ' . $this->value . ' ' . ($this->value === 1 ? rtrim($unit, 's') : $unit);
    }

    /** @return array{type:string, data_class?:string, value?:int, note?:string} the exported shape (flags have no value) */
    public function toArray(): array
    {
        $out = ['type' => $this->type];
        if ($this->dataClass !== null) {
            $out['data_class'] = $this->dataClass;
        }
        if (RequirementType::unit($this->type) !== RequirementType::UNIT_FLAG) {
            $out['value'] = $this->value;
        }
        if ($this->note !== null && $this->note !== '') {
            $out['note'] = $this->note;
        }

        return $out;
    }
}
