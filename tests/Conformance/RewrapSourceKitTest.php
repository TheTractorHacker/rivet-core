<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Crypto\RewrapSource;
use RivetCore\Testing\RewrapSourceConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedRewrapSource;

/** The kit against the in-memory reference source (and the harness target for the source mutants). */
final class RewrapSourceKitTest extends RewrapSourceConformanceTestCase
{
    use Flaw;

    protected function source(array $rows): RewrapSource
    {
        return new FlawedRewrapSource(self::$flaw, $rows);
    }

    protected function stored(RewrapSource $source, string $id): ?string
    {
        assert($source instanceof FlawedRewrapSource);

        return $source->get($id);
    }
}
