<?php

declare(strict_types=1);

namespace RivetCore\Automation;

/** Turns an event payload into the flat key => value map rule conditions compare against.
 *
 * @api
 */
final class EventContext
{
    /**
     * Nested arrays become dotted keys (['ticket' => ['priority' => 'High']] -> 'ticket.priority'); scalars become strings, booleans 1/0,
     * null is dropped (a missing key never matches, see AutomationRuleEvaluator). Lists of scalars are skipped, they have no single value.
     *
     * @param array<array-key,mixed> $data
     * @return array<string,string>
     */
    public static function flatten(array $data, string $prefix = '', int $depth = 0): array
    {
        $out = self::collect($data, $prefix, $depth);
        // The bare key also works when it is unambiguous (webhook payloads nest ticket fields under "ticket"): leaf name -> value. CORE-DS-58 (CORE-36): "unambiguous" means
        // exactly one nested path ends in that name; with two or more (metadata.priority and ticket.priority) there is no bare alias, so a field of an attacker-influenced subtree
        // cannot stand in for the real one by coming first.
        $leaves = [];
        foreach (array_keys($out) as $name) {
            $leaf = substr(strrchr('.' . $name, '.'), 1);
            $leaves[$leaf] = ($leaves[$leaf] ?? 0) + 1;
        }
        foreach ($out as $name => $value) {
            $leaf = substr(strrchr('.' . $name, '.'), 1);
            if ($leaf !== $name && !array_key_exists($leaf, $out) && $leaves[$leaf] === 1) {
                $out[$leaf] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<array-key,mixed> $data
     * @return array<string,string>
     */
    private static function collect(array $data, string $prefix, int $depth): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                if ($depth < 4 && !array_is_list($value)) {
                    $out += self::collect($value, $name, $depth + 1);
                }
            } elseif (is_bool($value)) {
                $out[$name] = $value ? '1' : '0';
            } elseif (is_scalar($value)) {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }
}
