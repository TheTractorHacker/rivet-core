<?php

declare(strict_types=1);

namespace RivetCore\Automation;

/**
 * Runs one matched rule's action through handlers the edition supplies (Core cannot create tickets or notify users itself).
 * {placeholders} in the action's text fields are replaced from the event context, e.g. "Backup failed: {backup.name}".
 *
 * @api
 */
final class AutomationExecutor
{
    /**
     * @param array<string,mixed> $rule a row of automation_rules
     * @param array<string,string> $context flat event context (EventContext::flatten)
     * @param array<string, callable(array<string,mixed>, array<string,string>, array<string,mixed>): (string|null)> $handlers action_type => handler(config, context, rule); returns a short result message
     * @return array{rule_id:int, ok:bool, message:string}
     */
    public function execute(array $rule, array $context, array $handlers): array
    {
        $id = (int) ($rule['rule_id'] ?? 0);
        $type = (string) ($rule['action_type'] ?? '');
        $handler = $handlers[$type] ?? null;
        if ($handler === null) {
            return ['rule_id' => $id, 'ok' => false, 'message' => "No handler for action '$type'"];
        }
        $config = json_decode((string) ($rule['action_config_json'] ?? ''), true);
        $config = is_array($config) ? self::interpolate($config, $context) : [];
        try {
            return ['rule_id' => $id, 'ok' => true, 'message' => (string) ($handler($config, $context, $rule) ?? 'done')];
        } catch (\Throwable $e) {
            return ['rule_id' => $id, 'ok' => false, 'message' => mb_substr($e->getMessage(), 0, 500)];
        }
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,string> $context
     * @return array<string,mixed>
     */
    public static function interpolate(array $config, array $context): array
    {
        foreach ($config as $k => $v) {
            // Never let event data steer where a request goes or who receives it: only free-text fields are interpolated.
            if (in_array($k, ['url', 'secret', 'user_id', 'client_id', 'priority'], true)) {
                continue;
            }
            if (is_string($v) && str_contains($v, '{')) {
                $config[$k] = preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', static fn (array $m) => $context[$m[1]] ?? '', $v);
            }
        }

        return $config;
    }
}
