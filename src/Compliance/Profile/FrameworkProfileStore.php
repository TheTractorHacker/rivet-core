<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Profile;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseException;
use RivetCore\Database\DatabaseInterface;

/**
 * Custom compliance profiles and the installation's choice, in the Core database (migration 0046).
 *
 *  - Built-in profiles ({@see BuiltInProfiles}) live in code: listed, readable, selectable, exportable and copyable, never changed, archived or deleted.
 *  - A custom profile has immutable VERSIONS. Every save that changes something adds the next version (a save that changes nothing adds nothing); the old
 *    versions stay readable and can be restored as a new version. History is never rewritten.
 *  - ONE profile is selected for the installation (data and backups are installation-wide, so the requirement is too; the per-customer checklist frameworks
 *    of `compliance_subjects` are untouched). Selecting follows the profile's current version: editing the selected profile changes what is checked and
 *    enforced from that moment.
 *
 * Authorization is the edition's: every method that writes assumes the caller already proved the user may administer compliance (it changes what the system
 * enforces, so require the highest administrative role). Each write is attributed to a user name and passed to the optional audit callback.
 *
 * @api
 */
final class FrameworkProfileStore implements RequirementsProviderInterface
{
    public const CHANGE_NOTE_MAX = 300;

    private const FLOOR_SQL = "SELECT s.profile_key, r.data_class, r.value_int
        FROM compliance_profile_selection s
        LEFT JOIN compliance_profiles p ON p.profile_key = s.profile_key
        LEFT JOIN compliance_profile_versions v ON v.profile_id = p.profile_id AND v.version = p.current_version
        LEFT JOIN compliance_profile_requirements r ON r.version_id = v.version_id AND r.req_type = 'retention_min'
        WHERE s.selection_id = 1";

    /**
     * @param (\Closure(string $action, array<string,scalar|null> $details): void)|null $audit called after every committed change (never with free text beyond
     *        the profile key, version and the user name); a failure inside it is logged and does not undo the change
     */
    public function __construct(private DatabaseInterface $database, private ClockInterface $clock, private ?\Closure $audit = null)
    {
    }

    // ------------------------------------------------------------------ reading

    /**
     * Built-ins first (in framework order), then custom profiles by name.
     *
     * @return list<FrameworkProfile>
     */
    public function list(bool $includeArchived = false): array
    {
        $out = array_values(BuiltInProfiles::all());
        $rows = $this->database->fetchAll(
            'SELECT p.profile_key FROM compliance_profiles p JOIN compliance_profile_versions v ON v.profile_id = p.profile_id AND v.version = p.current_version '
            . ($includeArchived ? '' : "WHERE p.status = 'active' ") . 'ORDER BY v.label, p.profile_key'
        );
        foreach ($rows as $r) {
            $p = $this->get((string) $r['profile_key']);
            if ($p !== null) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /** One profile at its current version (or a given one). Null when unknown. */
    public function get(string $key, ?int $version = null): ?FrameworkProfile
    {
        $builtIn = BuiltInProfiles::get($key);
        if ($builtIn !== null) {
            return $version === null || $version === 1 ? $builtIn : null;
        }
        if (preg_match(FrameworkProfile::KEY_PATTERN, $key) !== 1) {
            return null;
        }
        $row = $this->database->fetchOne(
            'SELECT p.profile_key, p.based_on, p.status, p.created_at, p.updated_at, v.version_id, v.version, v.label, v.description
             FROM compliance_profiles p JOIN compliance_profile_versions v ON v.profile_id = p.profile_id AND v.version = ' . ($version === null ? 'p.current_version' : '?') . '
             WHERE p.profile_key = ?',
            $version === null ? [$key] : [$version, $key]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): FrameworkProfile
    {
        $reqs = [];
        foreach ($this->database->fetchAll('SELECT req_type, data_class, value_int, note FROM compliance_profile_requirements WHERE version_id = ?', [(int) $row['version_id']]) as $r) {
            $reqs[] = new Requirement((string) $r['req_type'], (string) $r['data_class'] === '' ? null : (string) $r['data_class'], (int) $r['value_int'], $r['note'] === null || $r['note'] === '' ? null : (string) $r['note']);
        }

        return new FrameworkProfile(
            (string) $row['profile_key'],
            (string) $row['label'],
            (string) ($row['description'] ?? ''),
            (int) $row['version'],
            false,
            $reqs,
            (string) $row['status'],
            $row['based_on'] === null ? null : (string) $row['based_on'],
            '',
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /**
     * Every version of a custom profile, newest first.
     *
     * @return list<array{version:int, label:string, created_at:string, changed_by_name:string, change_note:?string, content_hash:string, current:bool}>
     */
    public function history(string $key): array
    {
        $rows = $this->database->fetchAll(
            'SELECT v.version, v.label, v.created_at, v.changed_by_name, v.change_note, v.content_hash, p.current_version
             FROM compliance_profiles p JOIN compliance_profile_versions v ON v.profile_id = p.profile_id WHERE p.profile_key = ? ORDER BY v.version DESC',
            [$key]
        );

        return array_map(static fn (array $r): array => [
            'version' => (int) $r['version'], 'label' => (string) $r['label'], 'created_at' => (string) $r['created_at'], 'changed_by_name' => (string) $r['changed_by_name'],
            'change_note' => $r['change_note'] === null ? null : (string) $r['change_note'], 'content_hash' => (string) $r['content_hash'], 'current' => (int) $r['version'] === (int) $r['current_version'],
        ], $rows);
    }

    /**
     * What changed between two versions of a custom profile.
     *
     * @return array{added:list<Requirement>, removed:list<Requirement>, changed:list<array{before:Requirement, after:Requirement}>, label_changed:bool, description_changed:bool}|null null when a version does not exist
     */
    public function diff(string $key, int $from, int $to): ?array
    {
        $a = $this->get($key, $from);
        $b = $this->get($key, $to);
        if ($a === null || $b === null) {
            return null;
        }
        $before = $after = [];
        foreach ($a->requirements as $r) {
            $before[$r->key()] = $r;
        }
        foreach ($b->requirements as $r) {
            $after[$r->key()] = $r;
        }
        $changed = [];
        foreach ($after as $k => $r) {
            if (isset($before[$k]) && ($before[$k]->value !== $r->value || $before[$k]->note !== $r->note)) {
                $changed[] = ['before' => $before[$k], 'after' => $r];
            }
        }

        return [
            'added' => array_values(array_diff_key($after, $before)),
            'removed' => array_values(array_diff_key($before, $after)),
            'changed' => $changed,
            'label_changed' => $a->label !== $b->label,
            'description_changed' => $a->description !== $b->description,
        ];
    }

    // ------------------------------------------------------------------ writing

    /**
     * @param list<Requirement> $requirements
     * @throws ProfileValidationException for an invalid profile or an existing key
     */
    public function create(string $key, string $label, string $description, array $requirements, ?int $userId, string $userName, ?string $basedOn = null, ?string $note = null, string $auditAction = 'profile.create'): FrameworkProfile
    {
        $by = self::person($userName);
        $profile = new FrameworkProfile($key, $label, $description, 1, false, $requirements, FrameworkProfile::STATUS_ACTIVE, $basedOn);
        $note = self::note($note);
        $now = $this->now();
        try {
            $this->database->transaction(function () use ($profile, $userId, $by, $note, $now): void {
                if ($this->database->fetchOne('SELECT 1 AS x FROM compliance_profiles WHERE profile_key = ?', [$profile->key]) !== null) {
                    throw new ProfileValidationException(['A framework with that key already exists.']);
                }
                $id = (int) $this->database->execute(
                    'INSERT INTO compliance_profiles (profile_key, based_on, current_version, status, created_by, created_at, updated_at) VALUES (?, ?, 1, ?, ?, ?, ?)',
                    [$profile->key, $profile->basedOn, FrameworkProfile::STATUS_ACTIVE, $userId, $now, $now]
                )->insertId;
                $this->insertVersion($id, $profile, 1, $userId, $by, $note ?? 'Created', $now);
            });
        } catch (DatabaseException $e) {
            if ($this->database->fetchOne('SELECT 1 AS x FROM compliance_profiles WHERE profile_key = ?', [$key]) !== null) {
                throw new ProfileValidationException(['A framework with that key already exists.']);   // lost a race to an identical create
            }
            throw $e;
        }
        $this->audited($auditAction, ['key' => $key, 'version' => 1, 'by' => $by]);

        return $this->get($key) ?? throw new \LogicException('The new profile was not stored.');
    }

    /**
     * A new custom profile with the content of another (built-in or custom, at its current version).
     *
     * @throws ProfileValidationException
     */
    public function copy(string $sourceKey, string $newKey, string $newLabel, ?int $userId, string $userName): FrameworkProfile
    {
        $src = $this->get($sourceKey) ?? throw new ProfileValidationException(['The framework to copy does not exist.']);

        return $this->create($newKey, $newLabel, $src->description, $src->requirements, $userId, $userName, $src->key, 'Copied from ' . $src->label . ($src->builtin ? '' : ' version ' . $src->version), 'profile.copy');
    }

    /**
     * Save new content as the next version. Nothing changes (and no version is added) when the content is identical.
     *
     * @param list<Requirement> $requirements
     * @param int|null $expectedVersion the version the editor started from; a different current version means someone saved in between and nothing is written
     * @throws ProfileValidationException for a built-in, an unknown profile, an archived profile, a stale edit or invalid content
     */
    public function update(string $key, string $label, string $description, array $requirements, ?int $userId, string $userName, ?string $changeNote = null, ?int $expectedVersion = null): FrameworkProfile
    {
        $by = self::person($userName);
        $note = self::note($changeNote);
        $now = $this->now();
        $result = $this->database->transaction(function () use ($key, $label, $description, $requirements, $userId, $by, $note, $expectedVersion, $now): ?int {
            $row = $this->lockedRow($key);
            $current = (int) $row['current_version'];
            if ($expectedVersion !== null && $expectedVersion !== $current) {
                throw new ProfileValidationException(['This framework was changed by someone else (now version ' . $current . '). Reload it and apply your changes again.']);
            }
            if ($row['status'] !== FrameworkProfile::STATUS_ACTIVE) {
                throw new ProfileValidationException(['An archived framework cannot be edited. Restore it first.']);
            }
            $profile = new FrameworkProfile($key, $label, $description, $current + 1, false, $requirements, FrameworkProfile::STATUS_ACTIVE, $row['based_on'] === null ? null : (string) $row['based_on']);
            $existing = $this->get($key);
            if ($existing !== null && $existing->contentHash() === $profile->contentHash()) {
                return null;
            }
            $this->insertVersion((int) $row['profile_id'], $profile, $current + 1, $userId, $by, $note, $now);
            $this->database->execute('UPDATE compliance_profiles SET current_version = ?, updated_at = ? WHERE profile_id = ?', [$current + 1, $now, (int) $row['profile_id']]);

            return $current + 1;
        });
        if ($result !== null) {
            $this->audited('profile.update', ['key' => $key, 'version' => (int) $result, 'by' => $by]);
        }

        return $this->get($key) ?? throw new \LogicException('The profile disappeared.');
    }

    /**
     * Make an older version current again by saving its content as a NEW version.
     *
     * @throws ProfileValidationException
     */
    public function revertTo(string $key, int $version, ?int $userId, string $userName): FrameworkProfile
    {
        $old = $this->get($key, $version);
        if ($old === null || $old->builtin) {
            throw new ProfileValidationException(['That version does not exist.']);
        }

        return $this->update($key, $old->label, $old->description, $old->requirements, $userId, $userName, 'Restored the content of version ' . $version);
    }

    /** @throws ProfileValidationException for a built-in, an unknown profile or the selected profile */
    public function archive(string $key, ?int $userId, string $userName): void
    {
        $by = self::person($userName);
        $now = $this->now();
        $this->database->transaction(function () use ($key, $now): void {
            $row = $this->lockedRow($key);
            if ($this->selectedKey() === $key) {
                throw new ProfileValidationException(['The selected framework cannot be archived. Select another one first.']);
            }
            $this->database->execute('UPDATE compliance_profiles SET status = ?, archived_at = ?, updated_at = ? WHERE profile_id = ?', [FrameworkProfile::STATUS_ARCHIVED, $now, $now, (int) $row['profile_id']]);
        });
        $this->audited('profile.archive', ['key' => $key, 'by' => $by]);
    }

    /** @throws ProfileValidationException */
    public function unarchive(string $key, ?int $userId, string $userName): void
    {
        $by = self::person($userName);
        $now = $this->now();
        $this->database->transaction(function () use ($key, $now): void {
            $row = $this->lockedRow($key);
            $this->database->execute('UPDATE compliance_profiles SET status = ?, archived_at = NULL, updated_at = ? WHERE profile_id = ?', [FrameworkProfile::STATUS_ACTIVE, $now, (int) $row['profile_id']]);
        });
        $this->audited('profile.unarchive', ['key' => $key, 'by' => $by]);
    }

    /**
     * Delete a custom profile with all its versions. Only a profile that was never selected can be deleted: a selected one is in use, and one that was
     * selected once appears in the selection log (evidence of what the installation worked to), so archive it instead.
     *
     * @throws ProfileValidationException
     */
    public function delete(string $key, ?int $userId, string $userName): void
    {
        $by = self::person($userName);
        $this->database->transaction(function () use ($key): void {
            $row = $this->lockedRow($key);
            if ($this->selectedKey() === $key) {
                throw new ProfileValidationException(['The selected framework cannot be deleted.']);
            }
            if ($this->database->fetchOne('SELECT 1 AS x FROM compliance_profile_selection_log WHERE profile_key = ? LIMIT 1', [$key]) !== null) {
                throw new ProfileValidationException(['This framework was used before, so it is kept as evidence. Archive it instead.']);
            }
            $id = (int) $row['profile_id'];
            $this->database->execute('DELETE FROM compliance_profile_requirements WHERE version_id IN (SELECT version_id FROM compliance_profile_versions WHERE profile_id = ?)', [$id]);
            $this->database->execute('DELETE FROM compliance_profile_versions WHERE profile_id = ?', [$id]);
            $this->database->execute('DELETE FROM compliance_profiles WHERE profile_id = ?', [$id]);
        });
        $this->audited('profile.delete', ['key' => $key, 'by' => $by]);
    }

    /** @return array<string,mixed> the locked profiles row */
    private function lockedRow(string $key): array
    {
        if (BuiltInProfiles::isBuiltIn($key)) {
            throw new ProfileValidationException(['A built-in framework cannot be changed. Copy it to make your own.']);
        }
        $row = preg_match(FrameworkProfile::KEY_PATTERN, $key) === 1
            ? $this->database->fetchOne('SELECT profile_id, current_version, status, based_on FROM compliance_profiles WHERE profile_key = ? FOR UPDATE', [$key])
            : null;

        return $row ?? throw new ProfileValidationException(['That framework does not exist.']);
    }

    private function insertVersion(int $profileId, FrameworkProfile $p, int $version, ?int $userId, string $by, ?string $note, string $now): void
    {
        $versionId = (int) $this->database->execute(
            'INSERT INTO compliance_profile_versions (profile_id, version, label, description, content_hash, change_note, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$profileId, $version, $p->label, $p->description === '' ? null : $p->description, $p->contentHash(), $note, $userId, $by, $now]
        )->insertId;
        foreach ($p->requirements as $r) {
            $this->database->execute(
                'INSERT INTO compliance_profile_requirements (version_id, req_type, data_class, value_int, note) VALUES (?, ?, ?, ?, ?)',
                [$versionId, $r->type, $r->dataClass ?? '', $r->value, $r->note]
            );
        }
    }

    // ------------------------------------------------------------------ selection

    /** The key of the selected profile, or null. */
    public function selectedKey(): ?string
    {
        $r = $this->database->fetchOne('SELECT profile_key FROM compliance_profile_selection WHERE selection_id = 1');

        return $r === null ? null : (string) $r['profile_key'];
    }

    public function selectedProfile(): ?FrameworkProfile
    {
        $key = $this->selectedKey();
        if ($key === null) {
            return null;
        }

        return $this->get($key) ?? throw new \RuntimeException('The selected compliance framework no longer exists.');
    }

    /**
     * Choose the framework this installation works to, or null to choose none (nothing new is checked or enforced, retention goes back to the settings).
     *
     * @throws ProfileValidationException for an unknown or archived profile
     */
    public function select(?string $key, ?int $userId, string $userName): void
    {
        $by = self::person($userName);
        $now = $this->now();
        $version = null;
        if ($key !== null) {
            $p = $this->get($key);
            if ($p === null || $p->status !== FrameworkProfile::STATUS_ACTIVE) {
                throw new ProfileValidationException(['That framework does not exist or is archived.']);
            }
            $version = $p->version;
        }
        $this->database->transaction(function () use ($key, $version, $userId, $by, $now): void {
            if ($key === null) {
                $this->database->execute('DELETE FROM compliance_profile_selection WHERE selection_id = 1');
            } else {
                $this->database->execute(
                    'INSERT INTO compliance_profile_selection (selection_id, profile_key, selected_by, selected_by_name, selected_at) VALUES (1, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE profile_key = VALUES(profile_key), selected_by = VALUES(selected_by), selected_by_name = VALUES(selected_by_name), selected_at = VALUES(selected_at)',
                    [$key, $userId, $by, $now]
                );
            }
            $this->database->execute(
                'INSERT INTO compliance_profile_selection_log (profile_key, profile_version, action, by_user, by_name, at) VALUES (?, ?, ?, ?, ?, ?)',
                [$key, $version, $key === null ? 'clear' : 'select', $userId, $by, $now]
            );
        });
        $this->audited($key === null ? 'profile.clear' : 'profile.select', ['key' => $key, 'version' => $version, 'by' => $by]);
    }

    /**
     * Who selected what and when, newest first.
     *
     * @return list<array{profile_key:?string, profile_version:?int, action:string, by_name:string, at:string}>
     */
    public function selectionLog(int $limit = 50): array
    {
        $rows = $this->database->fetchAll('SELECT profile_key, profile_version, action, by_name, at FROM compliance_profile_selection_log ORDER BY log_id DESC LIMIT ?', [max(1, min(500, $limit))]);

        return array_map(static fn (array $r): array => [
            'profile_key' => $r['profile_key'] === null ? null : (string) $r['profile_key'],
            'profile_version' => $r['profile_version'] === null ? null : (int) $r['profile_version'],
            'action' => (string) $r['action'], 'by_name' => (string) $r['by_name'], 'at' => (string) $r['at'],
        ], $rows);
    }

    /**
     * Minimum retention per data class of the selected profile, in ONE query (this is called from settings reads on busy paths). Empty when none is selected.
     *
     * @return array<string,int>
     */
    public function retentionFloors(): array
    {
        $rows = $this->database->fetchAll(self::FLOOR_SQL);
        if ($rows === []) {
            return [];
        }
        $key = (string) $rows[0]['profile_key'];
        if (BuiltInProfiles::isBuiltIn($key)) {
            return BuiltInProfiles::get($key)?->retentionFloors() ?? [];
        }
        $out = [];
        foreach ($rows as $r) {
            if ($r['data_class'] !== null && (string) $r['data_class'] !== '') {
                $out[(string) $r['data_class']] = (int) $r['value_int'];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ export and import

    /** @throws ProfileValidationException for an unknown profile or version */
    public function export(string $key, ?int $version = null): string
    {
        return ProfileCodec::export($this->get($key, $version) ?? throw new ProfileValidationException(['That framework does not exist.']));
    }

    /**
     * Create a custom profile from an exported file (version 1), or add the file's content as a new version of an existing custom profile.
     *
     * @param string|null $asKey store under this key instead of the file's key (for a collision)
     * @param bool $asNewVersion update the existing custom profile with the file's key instead of creating one
     * @throws ProfileValidationException with every problem of the file
     */
    public function import(string $json, ?int $userId, string $userName, ?string $asKey = null, bool $asNewVersion = false): FrameworkProfile
    {
        $p = ProfileCodec::parse($json);
        $key = $asKey ?? $p->key;
        if ($asNewVersion) {
            if ($this->get($key) === null) {
                throw new ProfileValidationException(['There is no framework with that key to add a version to.']);
            }

            return $this->update($key, $p->label, $p->description, $p->requirements, $userId, $userName, 'Imported from a file');
        }

        return $this->create($key, $p->label, $p->description, $p->requirements, $userId, $userName, $p->basedOn, 'Imported from a file', 'profile.import');
    }

    // ------------------------------------------------------------------ helpers

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function person(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 200 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1 || !mb_check_encoding($name, 'UTF-8')) {
            throw new ProfileValidationException(['Say who is making this change.']);
        }

        return $name;
    }

    private static function note(?string $note): ?string
    {
        if ($note === null || trim($note) === '') {
            return null;
        }
        $errors = [];
        $clean = ProfileText::clean($note, 'The change note', 0, self::CHANGE_NOTE_MAX, false, $errors);
        if ($clean === null) {
            throw new ProfileValidationException($errors);
        }

        return $clean;
    }

    /** @param array<string,scalar|null> $details */
    private function audited(string $action, array $details): void
    {
        if ($this->audit === null) {
            return;
        }
        try {
            ($this->audit)($action, $details);
        } catch (\Throwable $e) {
            error_log('compliance profile audit callback failed: ' . get_class($e));
        }
    }
}
