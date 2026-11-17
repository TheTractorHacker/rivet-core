<?php

declare(strict_types=1);

namespace RivetCore\Automation;

use RivetCore\Crypto\SettingsVault;
use RivetCore\Database\DatabaseInterface;

/**
 * Minimal automation rules engine for non-ticket events: matches `automation_rules` against audit-event type
 * strings. Deliberately evaluation-only: findMatchingRules() tells a caller which enabled rules fire for an
 * event; it does not execute create_ticket / send_webhook / notify_user. Execution belongs to the edition,
 * which knows what a ticket or a notification is.
 *
 * @api
 */
class AutomationRuleEvaluator
{
    private ?AutomationRuleStore $store;

    /**
     * @param SettingsVault|null $vault CRR-16: the vault the {@see AutomationRuleStore} seals `send_webhook` secrets with. Given one, the rules this returns carry the UNSEALED secret
     *        in `action_config_json` (that is what the executor's handler signs with); a rule whose secret cannot be opened is left out (it must not be delivered unsigned).
     */
    public function __construct(private DatabaseInterface $database, ?SettingsVault $vault = null)
    {
        $this->store = $vault === null ? null : new AutomationRuleStore($database, $vault);
    }

    /**
     * @param array<string,mixed> $eventData flat key/value context, e.g. ['entity_type' => 'contact', 'action' => 'started']
     * @return list<array<string,mixed>> enabled rules whose trigger_event matches and whose condition_json (if any) is satisfied
     */
    public function findMatchingRules(string $eventType, array $eventData): array
    {
        $rules = $this->database->fetchAll(
            'SELECT * FROM automation_rules WHERE trigger_event = ? AND is_enabled = 1',
            [$eventType]
        );

        $matched = array_values(array_filter(
            $rules,
            fn (array $rule) => $this->conditionsMatch($rule['condition_json'], $eventData)
        ));
        if ($this->store === null) {
            return $matched;
        }
        $out = [];
        foreach ($matched as $rule) {
            try {
                $out[] = $this->store->revealed($rule);
            } catch (\Throwable) {
                continue;   // fail closed: no delivery with a secret that cannot be opened
            }
        }

        return $out;
    }

    /**
     * All conditions AND together. No conditions (null/empty/'{}') always matches. A key missing from $eventData
     * never matches - missing is not treated as equal to any expected value, including null. Malformed JSON
     * fails closed.
     *
     * @param array<string,mixed> $eventData
     */
    public function conditionsMatch(?string $conditionJson, array $eventData): bool
    {
        if ($conditionJson === null || trim($conditionJson) === '' || trim($conditionJson) === '{}') {
            return true;
        }

        $conditions = json_decode($conditionJson, true);
        if (!is_array($conditions)) {
            return false;
        }

        foreach ($conditions as $field => $expected) {
            if (!array_key_exists($field, $eventData)) {
                return false;
            }
            if ((string) $eventData[$field] !== (string) $expected) {
                return false;
            }
        }

        return true;
    }
}
