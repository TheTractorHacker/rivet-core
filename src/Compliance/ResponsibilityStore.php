<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

use RivetCore\Database\DatabaseInterface;

/**
 * Who is responsible for a section of compliance, for an organization that outsources part of it to a managed service provider.
 * An assignment is keyed "section:<category>" (every item in that section) or "item:<id>" (one item, which overrides its section).
 * No assignment means the organization's own staff. The party's name is stored with the assignment so reports and snapshots stay
 * readable if the party is later renamed or removed.
 */
final class ResponsibilityStore
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public static function sectionKey(string $category): string
    {
        return 'section:' . $category;
    }

    public static function itemKey(string $itemId): string
    {
        return 'item:' . $itemId;
    }

    /** @return array<string, array{name:string, ref:?int}> keyed by assignment key */
    public function all(): array
    {
        $out = [];
        foreach ($this->database->fetchAll('SELECT assign_key, party_ref, party_name FROM compliance_responsibilities') as $r) {
            $out[(string) $r['assign_key']] = ['name' => (string) $r['party_name'], 'ref' => $r['party_ref'] === null ? null : (int) $r['party_ref']];
        }

        return $out;
    }

    /** @return array<string,string> key => party name, the form the assessor takes */
    public function names(): array
    {
        return array_map(static fn (array $a) => $a['name'], $this->all());
    }

    /** @throws \InvalidArgumentException for a malformed key or an empty name */
    public function assign(string $key, ?int $partyRef, string $partyName, ?int $byUserId): void
    {
        self::validateKey($key);
        $name = mb_substr(trim($partyName), 0, 200);
        if ($name === '') {
            throw new \InvalidArgumentException('Choose who is responsible.');
        }
        $this->database->execute(
            'INSERT INTO compliance_responsibilities (assign_key, party_ref, party_name, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE party_ref = VALUES(party_ref), party_name = VALUES(party_name), updated_by = VALUES(updated_by), updated_at = NOW()',
            [$key, $partyRef, $name, $byUserId]
        );
    }

    /** Back to the organization's own staff (or, for an item, back to its section's party). */
    public function clear(string $key): void
    {
        self::validateKey($key);
        $this->database->execute('DELETE FROM compliance_responsibilities WHERE assign_key = ?', [$key]);
    }

    /**
     * The party for one item: its own assignment, else its section's, else null (the organization's own staff).
     *
     * @param array<string,string> $names key => party name
     */
    public static function resolve(array $names, string $itemId, string $category): ?string
    {
        return $names[self::itemKey($itemId)] ?? $names[self::sectionKey($category)] ?? null;
    }

    private static function validateKey(string $key): void
    {
        if (!preg_match('/^(section|item):[^\x00-\x1f\x7f]{1,100}$/u', $key)) {
            throw new \InvalidArgumentException('Unknown compliance section.');
        }
    }
}
