<?php

declare(strict_types=1);

namespace RivetCore\Automation;

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

    public function __construct(private DatabaseInterface $database)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->database->fetchAll('SELECT * FROM automation_rules ORDER BY trigger_event, rule_id');
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->database->fetchOne('SELECT * FROM automation_rules WHERE rule_id = ?', [$id]);
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
        if (!preg_match('/^[a-z0-9_.]{1,150}$/', $triggerEvent)) {
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
            if (!preg_match('/^[A-Za-z0-9_.]{1,100}$/', $field) || !is_scalar($expected) || mb_strlen((string) $expected) > 200) {
                throw new \InvalidArgumentException('A condition has an invalid field or value.');
            }
            $clean[$field] = (string) $expected;
        }
        $cfg = $this->validateConfig($actionType, $config);
        $json = $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cfgJson = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($id === null) {
            return (int) $this->database->execute(
                'INSERT INTO automation_rules (name, trigger_event, condition_json, action_type, action_config_json, is_enabled) VALUES (?, ?, ?, ?, ?, ?)',
                [$name, $triggerEvent, $json, $actionType, $cfgJson, $enabled ? 1 : 0]
            )->insertId;
        }
        if ($this->find($id) === null) {
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

    /** @param array<string,mixed> $config @return array<string,mixed> */
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
                if (!$parts || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true) || empty($parts['host'])) {
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
