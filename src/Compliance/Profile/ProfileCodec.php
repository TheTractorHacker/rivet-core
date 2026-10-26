<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Profile;

/**
 * Export and import of a profile as JSON, schema `rivetcore-compliance-profile` version 1 (docs/design/compliance-frameworks.md section 6).
 *
 * Import is strict because the file is untrusted input: size cap, depth cap, exactly the documented keys at every level (an unknown key is an error, not
 * ignored), exact types (no numeric strings, no floats), the bounds of every requirement type, text rules ({@see ProfileText}: no control characters, no
 * credentials), at most {@see FrameworkProfile::MAX_REQUIREMENTS} requirements, no duplicates, and a schema version this release understands. The file's
 * `version` is informational only: an import always becomes version 1 of a new profile (or a new version of an existing one) and never overwrites history.
 * The exported file holds the profile's content and nothing else: no ids, user names, dates, settings or secrets.
 *
 * @api
 */
final class ProfileCodec
{
    public const FORMAT = 'rivetcore-compliance-profile';
    public const SCHEMA_VERSION = 1;
    public const MAX_BYTES = 65536;
    private const MAX_DEPTH = 6;

    private const TOP_KEYS = ['format', 'schema_version', 'profile'];
    private const PROFILE_KEYS = ['key', 'label', 'description', 'based_on', 'version', 'requirements'];
    private const REQUIREMENT_KEYS = ['type', 'data_class', 'value', 'note'];

    /** @return array<string,mixed> */
    public static function toArray(FrameworkProfile $p): array
    {
        return [
            'format' => self::FORMAT,
            'schema_version' => self::SCHEMA_VERSION,
            'profile' => [
                'key' => $p->key,
                'label' => $p->label,
                'description' => $p->description,
                'based_on' => $p->basedOn,
                'version' => $p->version,
                'requirements' => array_map(static fn (Requirement $r): array => $r->toArray(), $p->requirements),
            ],
        ];
    }

    public static function export(FrameworkProfile $p): string
    {
        return (string) json_encode(self::toArray($p), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * Parse and validate an import file. The result is a custom (not built-in), active profile at version 1.
     *
     * @throws ProfileValidationException with every problem found (at most 20)
     */
    public static function parse(string $json): FrameworkProfile
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new ProfileValidationException(['The file is larger than ' . intdiv(self::MAX_BYTES, 1024) . ' KiB.']);
        }
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3);
        }
        try {
            $doc = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new ProfileValidationException(['The file is not valid JSON (or is nested too deeply).']);
        }

        return self::fromArray($doc);
    }

    /**
     * @throws ProfileValidationException
     */
    public static function fromArray(mixed $doc): FrameworkProfile
    {
        /** @var list<string> $errors */
        $errors = [];
        if (!is_array($doc) || ($doc !== [] && array_is_list($doc))) {
            throw new ProfileValidationException(['The file must be a JSON object.']);
        }
        self::onlyKeys($doc, self::TOP_KEYS, 'the file', $errors);
        if (($doc['format'] ?? null) !== self::FORMAT) {
            throw new ProfileValidationException(['This is not a RivetCore compliance profile ("format" must be "' . self::FORMAT . '").']);
        }
        $sv = $doc['schema_version'] ?? null;
        if (!is_int($sv)) {
            throw new ProfileValidationException(['"schema_version" must be a whole number.']);
        }
        if ($sv > self::SCHEMA_VERSION) {
            throw new ProfileValidationException(['This file was written by a newer RivetCore (schema version ' . $sv . '); this release reads version ' . self::SCHEMA_VERSION . '.']);
        }
        if ($sv < 1) {
            throw new ProfileValidationException(['"schema_version" must be 1 or more.']);
        }
        $p = $doc['profile'] ?? null;
        if (!is_array($p) || ($p !== [] && array_is_list($p))) {
            throw new ProfileValidationException([...$errors, '"profile" must be an object.']);
        }
        self::onlyKeys($p, self::PROFILE_KEYS, 'the profile', $errors);

        $key = $p['key'] ?? null;
        if (!is_string($key) || preg_match(FrameworkProfile::KEY_PATTERN, $key) !== 1) {
            $errors[] = 'The key must be 3 to 48 characters: lower-case letters, digits and underscores, starting with a letter.';
            $key = null;
        } elseif (FrameworkProfile::isReservedKey($key)) {
            $errors[] = 'That key is reserved for a built-in framework. Choose another.';
            $key = null;
        }
        $label = ProfileText::clean($p['label'] ?? null, 'The name', 1, FrameworkProfile::LABEL_MAX, false, $errors);
        $description = array_key_exists('description', $p) ? ProfileText::clean($p['description'], 'The description', 0, FrameworkProfile::DESCRIPTION_MAX, true, $errors) : '';
        $basedOn = $p['based_on'] ?? null;
        if ($basedOn !== null && (!is_string($basedOn) || preg_match(FrameworkProfile::KEY_PATTERN, $basedOn) !== 1)) {
            $errors[] = '"based_on" must be a profile key or null.';
            $basedOn = null;
        }
        if (array_key_exists('version', $p) && (!is_int($p['version']) || $p['version'] < 1 || $p['version'] > 1000000)) {
            $errors[] = '"version" must be a whole number from 1 to 1000000.';
        }

        $reqs = [];
        $list = $p['requirements'] ?? null;
        if (!is_array($list) || ($list !== [] && !array_is_list($list))) {
            $errors[] = '"requirements" must be a list.';
        } elseif (count($list) > FrameworkProfile::MAX_REQUIREMENTS) {
            $errors[] = 'A profile may have at most ' . FrameworkProfile::MAX_REQUIREMENTS . ' requirements.';
        } else {
            foreach ($list as $i => $raw) {
                $r = self::requirement($raw, 'Requirement ' . ($i + 1), $errors);
                if ($r !== null) {
                    $reqs[] = $r;
                }
            }
        }
        if ($errors !== [] || $key === null || $label === null) {
            throw new ProfileValidationException($errors);
        }

        return new FrameworkProfile($key, $label, (string) $description, 1, false, $reqs, FrameworkProfile::STATUS_ACTIVE, $basedOn);
    }

    /**
     * @param list<string> $errors
     */
    private static function requirement(mixed $raw, string $where, array &$errors): ?Requirement
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $errors[] = "$where must be an object.";

            return null;
        }
        self::onlyKeys($raw, self::REQUIREMENT_KEYS, $where, $errors);
        $type = $raw['type'] ?? null;
        if (!is_string($type) || !RequirementType::isValid($type)) {
            $errors[] = "$where has an unknown type.";

            return null;
        }
        $class = $raw['data_class'] ?? null;
        if ($class !== null && !is_string($class)) {
            $errors[] = "$where: data_class must be text.";

            return null;
        }
        $unit = RequirementType::unit($type);
        if ($unit === RequirementType::UNIT_FLAG) {
            if (array_key_exists('value', $raw) && $raw['value'] !== true) {
                $errors[] = "$where (" . RequirementType::label($type) . ') is a yes/no requirement: leave out "value" or use true.';

                return null;
            }
            $value = 1;
        } else {
            $value = $raw['value'] ?? null;
            if (!is_int($value)) {
                $errors[] = "$where (" . RequirementType::label($type) . ') needs "value" as a whole number of ' . $unit . '.';

                return null;
            }
        }
        $note = null;
        if (array_key_exists('note', $raw) && $raw['note'] !== null) {
            $note = ProfileText::clean($raw['note'], "$where: the note", 0, Requirement::NOTE_MAX, false, $errors);
            if ($note === null) {
                return null;
            }
        }
        try {
            return new Requirement($type, $class, $value, $note === '' ? null : $note);
        } catch (\InvalidArgumentException $e) {
            $errors[] = "$where: " . $e->getMessage();

            return null;
        }
    }

    /**
     * @param array<array-key,mixed> $arr
     * @param list<string> $allowed
     * @param list<string> $errors
     */
    private static function onlyKeys(array $arr, array $allowed, string $where, array &$errors): void
    {
        foreach (array_keys($arr) as $k) {
            if (!in_array($k, $allowed, true)) {
                $errors[] = 'Unknown field in ' . $where . ': "' . mb_substr(preg_replace('/[^\x20-\x7e]/', '?', (string) $k) ?? '', 0, 40) . '".';
            }
        }
    }
}
