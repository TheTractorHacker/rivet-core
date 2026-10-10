<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Fields;

use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\Support\Sql;

/**
 * Custom fields: typed values attached to a client, a site (the edition's location) or a device (tables rmm_custom_fields and
 * rmm_custom_field_values). A definition has ONE scope: the place its values live. Types: text, number, bool, date, list (one of the
 * definition's options) and secret (sealed with the edition's SecretBox; a secret is never returned by a read, only injected into a script
 * parameter declared `secret` at run time, see {@see FieldPlaceholders}).
 *
 * Throws \InvalidArgumentException with a message that is safe to show. Authorization and audit are the caller's.
 *
 * @api
 */
final class CustomFieldService
{
    public const SCOPES = ['client', 'site', 'device'];
    public const TYPES = ['text', 'number', 'bool', 'date', 'list', 'secret'];
    public const MAX_FIELDS = 200;
    public const VALUE_MAX = 2000;
    public const SECRET_MASK = '********';

    public function __construct(private readonly Sql $sql, private readonly SecretBoxInterface $box, private readonly ?RmmTenancyInterface $tenancy = null)
    {
    }

    // ------------------------------------------------------------------ definitions

    /**
     * @param array<string,mixed> $in {name, label?, scope, type, options? (list), default?, description?}
     * @return array<string,mixed>
     */
    public function define(array $in, int $userId): array
    {
        $name = $in['name'] ?? null;
        if (!is_string($name) || !FieldPlaceholders::validName($name)) {
            throw new \InvalidArgumentException('A field name is lower case letters, digits and _, starts with a letter and is at most 40 characters.');
        }
        $scope = $in['scope'] ?? null;
        $type = $in['type'] ?? null;
        if (!is_string($scope) || !in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('scope must be client, site or device.');
        }
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('type must be text, number, bool, date, list or secret.');
        }
        if ((int) $this->sql->val('SELECT COUNT(*) FROM rmm_custom_fields') >= self::MAX_FIELDS) {
            throw new \InvalidArgumentException('At most ' . self::MAX_FIELDS . ' custom fields.');
        }
        if ($this->sql->one('SELECT field_id FROM rmm_custom_fields WHERE name = ?', [$name]) !== null) {
            throw new \InvalidArgumentException('A field with that name exists.');
        }
        [$options, $default, $label, $desc] = $this->attrs($type, $in, null);
        $now = $this->sql->utcNow();
        $id = $this->sql->insert('INSERT INTO rmm_custom_fields (name, label, scope, type, options_json, default_value, description, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$name, $label, $scope, $type, $options === [] ? null : (string) json_encode($options), $default, $desc, $userId, $now, $now]);

        return $this->field($id) ?? [];
    }

    /**
     * Change label, description, options or default. The name, scope and type are fixed once defined (values depend on them).
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function change(int $fieldId, array $in): array
    {
        $cur = $this->sql->one('SELECT * FROM rmm_custom_fields WHERE field_id = ?', [$fieldId]);
        if ($cur === null) {
            throw new \InvalidArgumentException('Field not found.');
        }
        foreach (['name', 'scope', 'type'] as $fixed) {
            if (isset($in[$fixed]) && $in[$fixed] !== $cur[$fixed]) {
                throw new \InvalidArgumentException("A field's $fixed cannot be changed. Define a new field instead.");
            }
        }
        [$options, $default, $label, $desc] = $this->attrs((string) $cur['type'], $in, $cur);
        $this->sql->run('UPDATE rmm_custom_fields SET label = ?, options_json = ?, default_value = ?, description = ?, updated_at = ? WHERE field_id = ?',
            [$label, $options === [] ? null : (string) json_encode($options), $default, $desc, $this->sql->utcNow(), $fieldId]);

        return $this->field($fieldId) ?? [];
    }

    public function delete(int $fieldId): bool
    {
        return $this->sql->transaction(function () use ($fieldId): bool {
            $this->sql->run('DELETE FROM rmm_custom_field_values WHERE field_id = ?', [$fieldId]);

            return $this->sql->run('DELETE FROM rmm_custom_fields WHERE field_id = ?', [$fieldId]) === 1;
        });
    }

    /** @return array<string,mixed>|null */
    public function field(int $fieldId): ?array
    {
        $r = $this->sql->one('SELECT f.*, (SELECT COUNT(*) FROM rmm_custom_field_values v WHERE v.field_id = f.field_id) AS value_count FROM rmm_custom_fields f WHERE f.field_id = ?', [$fieldId]);

        return $r === null ? null : self::fieldRow($r);
    }

    /** @return list<array<string,mixed>> */
    public function fields(?string $scope = null): array
    {
        $out = [];
        $rows = $scope === null
            ? $this->sql->all('SELECT f.*, (SELECT COUNT(*) FROM rmm_custom_field_values v WHERE v.field_id = f.field_id) AS value_count FROM rmm_custom_fields f ORDER BY f.scope, f.name')
            : $this->sql->all('SELECT f.*, (SELECT COUNT(*) FROM rmm_custom_field_values v WHERE v.field_id = f.field_id) AS value_count FROM rmm_custom_fields f WHERE f.scope = ? ORDER BY f.name', [$scope]);
        foreach ($rows as $r) {
            $out[] = self::fieldRow($r);
        }

        return $out;
    }

    // ------------------------------------------------------------------ values

    /**
     * Set (or, with null or an empty string, clear) the value of a field for a client, site or device.
     *
     * @return array<string,mixed> the stored value as a read shows it
     */
    public function setValue(int $fieldId, int $scopeId, mixed $value, int $userId): array
    {
        $f = $this->sql->one('SELECT * FROM rmm_custom_fields WHERE field_id = ?', [$fieldId]);
        if ($f === null) {
            throw new \InvalidArgumentException('Field not found.');
        }
        if ($scopeId <= 0) {
            throw new \InvalidArgumentException('Choose the ' . $f['scope'] . ' the value belongs to.');
        }
        $this->checkTarget((string) $f['scope'], $scopeId);
        if ($value === null || $value === '') {
            $this->sql->run('DELETE FROM rmm_custom_field_values WHERE field_id = ? AND scope_id = ?', [$fieldId, $scopeId]);

            return ['field_id' => $fieldId, 'scope_id' => $scopeId, 'value' => null, 'set' => false];
        }
        $clean = self::cleanValue((string) $f['type'], $value, self::optionsOf($f));
        $secret = $f['type'] === 'secret';
        $now = $this->sql->utcNow();
        $this->sql->transaction(function () use ($fieldId, $scopeId, $clean, $secret, $userId, $now): void {
            $cur = $this->sql->one('SELECT field_id FROM rmm_custom_field_values WHERE field_id = ? AND scope_id = ? FOR UPDATE', [$fieldId, $scopeId]);
            $text = $secret ? null : $clean;
            $enc = $secret ? $this->box->encrypt($clean) : null;
            if ($cur === null) {
                $this->sql->run('INSERT INTO rmm_custom_field_values (field_id, scope_id, value_text, value_enc, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$fieldId, $scopeId, $text, $enc, $userId, $now]);
            } else {
                $this->sql->run('UPDATE rmm_custom_field_values SET value_text = ?, value_enc = ?, updated_by = ?, updated_at = ? WHERE field_id = ? AND scope_id = ?', [$text, $enc, $userId, $now, $fieldId, $scopeId]);
            }
        });

        return ['field_id' => $fieldId, 'scope_id' => $scopeId, 'value' => $secret ? self::SECRET_MASK : $clean, 'set' => true];
    }

    /**
     * The values stored for one client, site or device, secrets masked.
     *
     * @return list<array<string,mixed>>
     */
    public function valuesFor(string $scope, int $scopeId): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT f.field_id, f.name, f.label, f.type, f.default_value, v.value_text, v.value_enc, v.updated_at FROM rmm_custom_fields f
            LEFT JOIN rmm_custom_field_values v ON v.field_id = f.field_id AND v.scope_id = ? WHERE f.scope = ? ORDER BY f.name', [$scopeId, $scope]) as $r) {
            $set = $r['value_text'] !== null || $r['value_enc'] !== null;
            $out[] = ['field_id' => (int) $r['field_id'], 'name' => (string) $r['name'], 'label' => (string) $r['label'], 'type' => (string) $r['type'], 'set' => $set,
                'value' => $r['type'] === 'secret' ? ($set ? self::SECRET_MASK : null) : ($set ? (string) $r['value_text'] : null),
                'default' => $r['type'] === 'secret' ? null : $r['default_value'], 'updated_at' => $r['updated_at'] === null ? null : \RivetCore\Rmm\Support\Sql::iso((string) $r['updated_at'])];
        }

        return $out;
    }

    /**
     * Every field value that applies to a device, by field name: a client field takes the device's client's value, a site field the device's location's,
     * a device field its own; with no value the definition's default applies. Secrets are decrypted only when $withSecrets is set (a run resolving a
     * script's `secret` parameter), never otherwise.
     *
     * @param array<string,mixed> $dev the device row
     * @param list<string>|null $names only these fields (a run needs the ones its parameters name; the others are neither read nor decrypted)
     * @return array<string,array{type:string,value:?string}>
     */
    public function resolveFor(array $dev, bool $withSecrets = false, ?array $names = null): array
    {
        $where = '';
        $params = [(int) $dev['client_id'], (int) $dev['location_id'], (int) $dev['device_id']];
        if ($names !== null) {
            if ($names === []) {
                return [];
            }
            $where = ' WHERE f.name IN (' . implode(',', array_fill(0, count($names), '?')) . ')';
            array_push($params, ...$names);
        }
        $rows = $this->sql->all("SELECT f.name, f.type, f.default_value, v.value_text, v.value_enc FROM rmm_custom_fields f
            LEFT JOIN rmm_custom_field_values v ON v.field_id = f.field_id AND v.scope_id = CASE f.scope WHEN 'client' THEN ? WHEN 'site' THEN ? ELSE ? END$where",
            $params);
        $out = [];
        foreach ($rows as $r) {
            $value = null;
            if ($r['type'] === 'secret') {
                if ($withSecrets && is_string($r['value_enc']) && $r['value_enc'] !== '') {
                    try {
                        $value = $this->box->decrypt($r['value_enc']);
                    } catch (\Throwable) {
                        $value = null;
                    }
                } elseif (!$withSecrets && $r['value_enc'] !== null) {
                    $value = self::SECRET_MASK;
                }
            } else {
                $value = $r['value_text'] !== null ? (string) $r['value_text'] : ($r['default_value'] !== null ? (string) $r['default_value'] : null);
            }
            $out[(string) $r['name']] = ['type' => (string) $r['type'], 'value' => $value];
        }

        return $out;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed>|null $cur
     * @return array{0:list<string>,1:?string,2:string,3:string}
     */
    private function attrs(string $type, array $in, ?array $cur): array
    {
        $options = $cur === null ? [] : self::optionsOf($cur);
        if (array_key_exists('options', $in)) {
            $o = $in['options'];
            if ($type !== 'list') {
                if ($o !== null && $o !== []) {
                    throw new \InvalidArgumentException('Only a list field has options.');
                }
                $options = [];
            } else {
                if (!is_array($o) || !array_is_list($o) || $o === [] || count($o) > 100) {
                    throw new \InvalidArgumentException('A list field needs 1 to 100 options.');
                }
                $options = [];
                foreach ($o as $x) {
                    if (!is_string($x) || trim($x) === '' || mb_strlen($x) > 100 || preg_match('/[\x00-\x1f]/', $x) === 1) {
                        throw new \InvalidArgumentException('An option is text of 1 to 100 characters.');
                    }
                    $options[trim($x)] = trim($x);
                }
                $options = array_values($options);
            }
        }
        if ($type === 'list' && $options === []) {
            throw new \InvalidArgumentException('A list field needs options.');
        }
        $default = $cur === null ? null : ($cur['default_value'] === null ? null : (string) $cur['default_value']);
        if (array_key_exists('default', $in)) {
            if ($type === 'secret' && $in['default'] !== null && $in['default'] !== '') {
                throw new \InvalidArgumentException('A secret field has no default.');
            }
            $default = $in['default'] === null || $in['default'] === '' ? null : self::cleanValue($type, $in['default'], $options);
        }
        $label = array_key_exists('label', $in) ? self::text($in['label'], 100) : (string) ($cur['label'] ?? '');
        $desc = array_key_exists('description', $in) ? self::text($in['description'], 300) : (string) ($cur['description'] ?? '');

        return [$options, $default, $label, $desc];
    }

    /** @param list<string> $options */
    public static function cleanValue(string $type, mixed $value, array $options = []): string
    {
        switch ($type) {
            case 'number':
                if (is_string($value)) {
                    $value = trim($value);
                }
                if ((!is_int($value) && !is_float($value) && !(is_string($value) && preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', $value) === 1)) || (is_float($value) && !is_finite($value))) {
                    throw new \InvalidArgumentException('A number field takes a number.');
                }

                $n = is_string($value) ? $value : (string) $value;

                return str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;
            case 'bool':
                if (is_string($value)) {
                    $value = match (strtolower(trim($value))) {
                        'true', '1', 'yes', 'on' => true,
                        'false', '0', 'no', 'off' => false,
                        default => $value,
                    };
                }
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException('A bool field takes true or false.');
                }

                return $value ? 'true' : 'false';
            case 'date':
                $d = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value)) : false;
                if ($d === false || $d->format('Y-m-d') !== trim((string) $value)) {
                    throw new \InvalidArgumentException('A date field takes YYYY-MM-DD.');
                }

                return $d->format('Y-m-d');
            case 'list':
                if (!is_string($value) || !in_array($value, $options, true)) {
                    throw new \InvalidArgumentException('That is not one of the field\'s options.');
                }

                return $value;
            default:   // text, secret
                if (is_int($value) || is_float($value)) {
                    $value = (string) $value;
                }
                if (!is_string($value) || strlen($value) > self::VALUE_MAX || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8')) {
                    throw new \InvalidArgumentException('Text of at most ' . self::VALUE_MAX . ' bytes (UTF-8) is expected.');
                }

                return $value;
        }
    }

    private function checkTarget(string $scope, int $id): void
    {
        $ok = match ($scope) {
            'client' => $this->tenancy === null || $this->tenancy->clientName($id) !== null,
            'device' => $this->sql->one('SELECT device_id FROM endpoint_agent_devices WHERE device_id = ?', [$id]) !== null,
            default => true,
        };
        if (!$ok) {
            throw new \InvalidArgumentException('That ' . $scope . ' does not exist.');
        }
    }

    /**
     * @param array<string,mixed> $r
     * @return list<string>
     */
    private static function optionsOf(array $r): array
    {
        $o = is_string($r['options_json'] ?? null) ? json_decode($r['options_json'], true) : null;

        return is_array($o) ? array_values(array_map('strval', $o)) : [];
    }

    private static function text(mixed $v, int $max): string
    {
        $s = is_string($v) ? trim($v) : '';
        if (mb_strlen($s) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $s) === 1) {
            throw new \InvalidArgumentException("Text is limited to $max characters.");
        }

        return $s;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function fieldRow(array $r): array
    {
        return ['field_id' => (int) $r['field_id'], 'name' => (string) $r['name'], 'label' => (string) $r['label'], 'scope' => (string) $r['scope'], 'type' => (string) $r['type'],
            'options' => self::optionsOf($r), 'default' => $r['type'] === 'secret' ? null : $r['default_value'], 'description' => (string) $r['description'],
            'values' => (int) ($r['value_count'] ?? 0)];
    }
}
