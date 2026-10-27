<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Software;

/**
 * The change-detection hash of a software list, shared byte for byte with the agent (endpoint-agent/internal/collect/software.go; the
 * vectors in endpoint-agent/testdata/software/hash_vectors.json are asserted by both sides).
 *
 * One line per item, `source TAB name TAB version TAB publisher LF` (publisher empty when unknown), the lines sorted bytewise, the
 * SHA-256 of their concatenation in lower-case hex. Install dates are not part of it, so a date correction never forces a resync.
 *
 * @api
 */
final class SoftwareHash
{
    /** SHA-256 of the empty list (a machine that reports no software). */
    public const EMPTY = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * @param iterable<array{source:string,name:string,version:string,publisher:?string}> $items
     */
    public static function of(iterable $items): string
    {
        $lines = [];
        foreach ($items as $i) {
            $lines[] = $i['source'] . "\t" . $i['name'] . "\t" . $i['version'] . "\t" . ($i['publisher'] ?? '') . "\n";
        }
        sort($lines, SORT_STRING);

        return hash('sha256', implode('', $lines));
    }

    /** The identity of an item on one device: exact source and name (case and spacing are significant). */
    public static function key(string $source, string $name): string
    {
        return sha1($source . "\0" . $name);
    }
}
