<?php

declare(strict_types=1);

namespace RivetCore\Automation;

use RivetCore\Crypto\SettingsVault;
use RivetCore\Database\DatabaseInterface;

/** Validated create/read/update/delete for event automation rules (the Core-owned `automation_rules` table).
 *
 * @api
 */
final class AutomationRuleStore
{
    public const ACTIONS = [
        'create_ticket' => 'Create a ticket',
        'send_webhook' => 'Send a webhook',
        'notify_user' => 'Notify a user',
    ];

    /** The vault context name of a webhook rule's signing secret. */
    public const SECRET_CONTEXT = 'automation.webhook_secret';

    /**
     * @param SettingsVault|null $vault CORE-DS-58: with a vault the `send_webhook` signing secret is stored sealed (`secret_enc`, never in clear) and is left out of every row that
     *        {@see all()} and {@see find()} return unless the caller asks for it (`$withSecret`, for the code that signs the delivery). Without one nothing changes.
     */
    public function __construct(private DatabaseInterface $database, private ?SettingsVault $vault = null)
    {
    }

    /**
     * @param bool $withSecret true for the code that sends the webhook: the row then carries the unsealed secret in `action_config_json` exactly as before
     * @return list<array<string,mixed>>
     */
    public function all(bool $withSecret = false): array
    {
        return array_map(fn (array $r): array => $this->present($r, $withSecret), $this->database->fetchAll('SELECT * FROM automation_rules ORDER BY trigger_event, rule_id'));
    }

    /**
     * @param bool $withSecret see {@see all()}
     * @return array<string,mixed>|null
     */
    public function find(int $id, bool $withSecret = false): ?array
    {
        $r = $this->database->fetchOne('SELECT * FROM automation_rules WHERE rule_id = ?', [$id]);

        return $r === null ? null : $this->present($r, $withSecret);
    }

    /**
     * CORE-DS-58: seal every `send_webhook` secret that is still stored in clear (a rule saved before the vault was given to the store). Safe to run again; call it once after the
     * upgrade (for example from the edition's housekeeping). A lazy version of the same runs whenever such a row is read.
     *
     * @return int rules sealed
     */
    public function sealStoredSecrets(): int
    {
        if ($this->vault === null) {
            return 0;
        }
        $n = 0;
        foreach ($this->database->fetchAll("SELECT rule_id, action_config_json FROM automation_rules WHERE action_type = 'send_webhook'") as $r) {
            $cfg = json_decode((string) $r['action_config_json'], true);
            if (is_array($cfg) && is_string($cfg['secret'] ?? null) && $cfg['secret'] !== '') {
                $cfg['secret_enc'] = $this->vault->encrypt(self::SECRET_CONTEXT, $cfg['secret']);
                $cfg['secret'] = '';
                $this->database->execute('UPDATE automation_rules SET action_config_json = ? WHERE rule_id = ?', [json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $r['rule_id']]);
                ++$n;
            }
        }

        return $n;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row, bool $withSecret): array
    {
        if ($this->vault === null || ($row['action_type'] ?? '') !== 'send_webhook') {
            return $row;
        }
        $cfg = json_decode((string) ($row['action_config_json'] ?? ''), true);
        if (!is_array($cfg)) {
            return $row;
        }
        if (is_string($cfg['secret'] ?? null) && $cfg['secret'] !== '' && !isset($cfg['secret_enc'])) {
            $this->sealStoredSecrets();   // a clear secret read through a store that has a vault: seal it now
            $cfg['secret_enc'] = $this->vault->encrypt(self::SECRET_CONTEXT, $cfg['secret']);
        }
        $enc = is_string($cfg['secret_enc'] ?? null) ? $cfg['secret_enc'] : '';
        $plain = '';
        if ($withSecret && $enc !== '') {
            $plain = $this->vault->decrypt(self::SECRET_CONTEXT, $enc);
        }
        unset($cfg['secret_enc']);
        $cfg['secret'] = $plain;
        $cfg['secret_set'] = $enc !== '';
        $row['action_config_json'] = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $row;
    }

    /**
     * @param array<string,mixed> $conditions field => expected value (all must match)
     * @param array<string,mixed> $config per action: create_ticket {subject, details?, priority?, client_id?};
     *                                    send_webhook {url, secret?}; notify_user {user_id?, message}
     * @throws \InvalidArgumentException with a message safe to show the administrator
     */
    public function save(?int $id, string $name, string $triggerEvent, array $conditions, string $actionType, array $config, bool $enabled): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 200) {
            throw new \InvalidArgumentException('Give the rule a name (up to 200 characters).');
        }
        $triggerEvent = trim($triggerEvent);
        if (!preg_match('/^[a-z0-9_.]{1,150}\z/D', $triggerEvent)) {
            throw new \InvalidArgumentException('Choose the event that triggers the rule.');
        }
        if (!isset(self::ACTIONS[$actionType])) {
            throw new \InvalidArgumentException('Choose what the rule does.');
        }
        $clean = [];
        foreach ($conditions as $field => $expected) {
            $field = trim((string) $field);
            if ($field === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_.]{1,100}\z/D', $field) || !is_scalar($expected) || mb_strlen((string) $expected) > 200) {
                throw new \InvalidArgumentException('A condition has an invalid field or value.');
            }
            $clean[$field] = (string) $expected;
        }
        $cfg = $this->validateConfig($actionType, $config);
        if ($this->vault !== null && $actionType === 'send_webhook') {
            $keep = !empty($config['keep_secret']) && $id !== null;   // the form did not re-enter it: leave the sealed one alone
            if ($keep) {
                $old = $this->database->fetchOne('SELECT action_config_json FROM automation_rules WHERE rule_id = ?', [$id]);
                $oldCfg = $old === null ? [] : (json_decode((string) $old['action_config_json'], true) ?: []);
                $cfg['secret_enc'] = is_string($oldCfg['secret_enc'] ?? null) ? $oldCfg['secret_enc'] : $this->vault->encrypt(self::SECRET_CONTEXT, (string) ($oldCfg['secret'] ?? ''));
            } else {
                $cfg['secret_enc'] = $this->vault->encrypt(self::SECRET_CONTEXT, (string) $cfg['secret']);
            }
            $cfg['secret'] = '';
        }
        $json = $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cfgJson = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($id === null) {
            return (int) $this->database->execute(
                'INSERT INTO automation_rules (name, trigger_event, condition_json, action_type, action_config_json, is_enabled) VALUES (?, ?, ?, ?, ?, ?)',
                [$name, $triggerEvent, $json, $actionType, $cfgJson, $enabled ? 1 : 0]
            )->insertId;
        }
        if ($this->database->fetchOne('SELECT 1 AS x FROM automation_rules WHERE rule_id = ?', [$id]) === null) {
            throw new \InvalidArgumentException('That rule no longer exists.');
        }
        $this->database->execute(
            'UPDATE automation_rules SET name = ?, trigger_event = ?, condition_json = ?, action_type = ?, action_config_json = ?, is_enabled = ? WHERE rule_id = ?',
            [$name, $triggerEvent, $json, $actionType, $cfgJson, $enabled ? 1 : 0, $id]
        );

        return $id;
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $this->database->execute('UPDATE automation_rules SET is_enabled = ? WHERE rule_id = ?', [$enabled ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $this->database->execute('DELETE FROM automation_rules WHERE rule_id = ?', [$id]);
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function validateConfig(string $type, array $config): array
    {
        $text = static fn (string $k, int $max): string => mb_substr(trim((string) ($config[$k] ?? '')), 0, $max);
        switch ($type) {
            case 'create_ticket':
                $subject = $text('subject', 500);
                if ($subject === '') {
                    throw new \InvalidArgumentException('Enter the subject for the ticket that will be created.');
                }
                $priority = in_array($config['priority'] ?? '', ['Low', 'Medium', 'High'], true) ? $config['priority'] : 'Low';

                return ['subject' => $subject, 'details' => $text('details', 5000), 'priority' => $priority, 'client_id' => max(0, (int) ($config['client_id'] ?? 0))];
            case 'send_webhook':
                $url = $text('url', 500);
                $parts = parse_url($url);
                // Same URL-shape rules as Webhooks\UrlPolicy (no whitespace/control characters, backslash or credentials); the
                // address itself is checked at send time, so the edition's send_webhook handler must go through UrlPolicy.
                if (!$parts || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url) === 1 || isset($parts['user']) || isset($parts['pass'])
                    || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true) || empty($parts['host'])) {
                    throw new \InvalidArgumentException('Enter a valid http(s) URL for the webhook.');
                }

                return ['url' => $url, 'secret' => $text('secret', 200)];
            case 'notify_user':
                $message = $text('message', 1000);
                if ($message === '') {
                    throw new \InvalidArgumentException('Enter the notification message.');
                }

                return ['message' => $message, 'user_id' => max(0, (int) ($config['user_id'] ?? 0))];
        }
        throw new \InvalidArgumentException('Unknown action.');
    }
}
