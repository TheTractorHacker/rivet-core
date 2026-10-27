<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Profile;

use RivetCore\Compliance\Framework;

/**
 * A compliance framework profile: a name and a set of {@see Requirement}s that Core checks and (for retention) enforces. Immutable and always valid.
 *
 * Built-in profiles ({@see BuiltInProfiles}) are read-only and carry the key of a {@see Framework}; custom profiles are created by an administrator, copied
 * from a built-in, edited (each edit is a new version), exported and imported.
 *
 * @api
 */
final readonly class FrameworkProfile
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{2,47}\z/D';
    public const LABEL_MAX = 120;
    public const DESCRIPTION_MAX = 1000;
    public const MAX_REQUIREMENTS = 64;
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    /** @var list<Requirement> in canonical order (type order, then data class order) */
    public array $requirements;

    /**
     * @param list<Requirement> $requirements
     * @throws ProfileValidationException
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public int $version,
        public bool $builtin,
        array $requirements,
        public string $status = self::STATUS_ACTIVE,
        public ?string $basedOn = null,
        public string $shortLabel = '',
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
        $errors = [];
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            $errors[] = 'The key must be 3 to 48 characters: lower-case letters, digits and underscores, starting with a letter.';
        } elseif (!$builtin && self::isReservedKey($key)) {
            $errors[] = 'That key is reserved for a built-in framework. Choose another.';
        }
        if (!$builtin) {
            $errors = [...$errors, ...self::checkText($label, $description)];
        }
        if ($version < 1) {
            $errors[] = 'The version must be 1 or more.';
        }
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true)) {
            $errors[] = 'Unknown profile status.';
        }
        if ($basedOn !== null && preg_match(self::KEY_PATTERN, $basedOn) !== 1) {
            $errors[] = 'The "based on" key is not a valid profile key.';
        }
        if (count($requirements) > self::MAX_REQUIREMENTS) {
            $errors[] = 'A profile may have at most ' . self::MAX_REQUIREMENTS . ' requirements.';
        }
        $seen = [];
        foreach ($requirements as $r) {
            if (isset($seen[$r->key()])) {
                $errors[] = 'Requirement "' . $r->describe() . '" is listed twice.';
            }
            $seen[$r->key()] = true;
        }
        if ($errors !== []) {
            throw new ProfileValidationException($errors);
        }
        $types = array_flip(RequirementType::ids());
        $classes = array_flip(DataClass::ids());
        usort($requirements, static fn (Requirement $a, Requirement $b): int => [$types[$a->type], $classes[$a->dataClass ?? ''] ?? -1] <=> [$types[$b->type], $classes[$b->dataClass ?? ''] ?? -1]);
        $this->requirements = $requirements;
    }

    public static function isReservedKey(string $key): bool
    {
        return Framework::isValid($key) || $key === 'none';
    }

    /**
     * @return list<string> the reasons the label or description is not acceptable
     */
    public static function checkText(string $label, string $description): array
    {
        $errors = [];
        ProfileText::clean($label, 'The name', 1, self::LABEL_MAX, false, $errors);
        ProfileText::clean($description, 'The description', 0, self::DESCRIPTION_MAX, true, $errors);

        return $errors;
    }

    public function requirement(string $type, ?string $dataClass = null): ?Requirement
    {
        foreach ($this->requirements as $r) {
            if ($r->type === $type && $r->dataClass === $dataClass) {
                return $r;
            }
        }

        return null;
    }

    public function has(string $type): bool
    {
        foreach ($this->requirements as $r) {
            if ($r->type === $type) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Requirement> */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->requirements, static fn (Requirement $r): bool => $r->type === $type));
    }

    /** The minimum retention in days the profile asks for a data class; 0 when it asks for none. */
    public function retentionMin(string $dataClass): int
    {
        return $this->requirement(RequirementType::RETENTION_MIN, $dataClass)->value ?? 0;
    }

    /** @return array<string,int> data class => minimum days, only the classes that have one */
    public function retentionFloors(): array
    {
        $out = [];
        foreach ($this->ofType(RequirementType::RETENTION_MIN) as $r) {
            $out[(string) $r->dataClass] = $r->value;
        }

        return $out;
    }

    /** "Contract X (version 3)" or the label alone for a built-in. */
    public function displayName(): string
    {
        return $this->builtin ? $this->label : $this->label . ' (version ' . $this->version . ')';
    }

    /**
     * Hash of what the profile SAYS (label, description, requirements): the same content has the same hash whatever its key, version or dates. Used to skip
     * a save that changes nothing and to tell two versions apart.
     */
    public function contentHash(): string
    {
        return hash('sha256', (string) json_encode([
            'label' => $this->label,
            'description' => $this->description,
            'requirements' => array_map(static fn (Requirement $r): array => $r->toArray(), $this->requirements),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
