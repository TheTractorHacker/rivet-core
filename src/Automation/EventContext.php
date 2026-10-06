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
        $out = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                if ($depth < 4 && !array_is_list($value)) {
                    $out += self::flatten($value, $name, $depth + 1);
                }
            } elseif (is_bool($value)) {
                $out[$name] = $value ? '1' : '0';
            } elseif (is_scalar($value)) {
                $out[$name] = (string) $value;
            }
        }
        // The bare key also works when it is unambiguous (webhook payloads nest ticket fields under "ticket"): leaf name -> value.
        foreach ($out as $name => $value) {
            $leaf = substr(strrchr('.' . $name, '.'), 1);
            if ($leaf !== $name && !array_key_exists($leaf, $out)) {
                $out[$leaf] = $value;
            }
        }

        return $out;
    }
}
