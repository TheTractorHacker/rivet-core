<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

/**
 * Names the units (exchanges and snapshot tables) in which two golden transcript documents differ. A unit is `step:<id>` for an
 * exchange or hook, `snapshot:<name>:<table>` for one table of a snapshot, or `structure:<what>` for anything coarser.
 */
final class GoldenDelta
{
    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return list<string>
     */
    public static function units(array $a, array $b): array
    {
        $out = [];
        foreach (['scenario', 'format'] as $k) {
            if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
                $out[] = "structure:$k";
            }
        }
        $ids = static fn (array $d): array => array_map(static fn (array $s): string => (string) $s['id'], $d['steps'] ?? []);
        if ($ids($a) !== $ids($b)) {
            $out[] = 'structure:step-order';
        }
        $x = array_column($a['steps'] ?? [], null, 'id');
        $y = array_column($b['steps'] ?? [], null, 'id');
        foreach (array_unique(array_merge(array_keys($x), array_keys($y))) as $id) {
            if (($x[$id] ?? null) !== ($y[$id] ?? null)) {
                $out[] = "step:$id";
            }
        }
        $sa = $a['snapshots'] ?? [];
        $sb = $b['snapshots'] ?? [];
        foreach (array_unique(array_merge(array_keys($sa), array_keys($sb))) as $name) {
            $p = $sa[$name] ?? [];
            $q = $sb[$name] ?? [];
            foreach (array_unique(array_merge(array_keys($p), array_keys($q))) as $table) {
                if (($p[$table] ?? null) !== ($q[$table] ?? null)) {
                    $out[] = "snapshot:$name:$table";
                }
            }
        }
        $known = ['scenario', 'format', 'steps', 'snapshots'];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            if (!in_array($k, $known, true) && ($a[$k] ?? null) !== ($b[$k] ?? null)) {
                $out[] = "structure:$k";
            }
        }
        sort($out);

        return $out;
    }
}
