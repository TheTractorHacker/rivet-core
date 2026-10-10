<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Crypto\EnvelopeInterface;
use RivetCore\Crypto\KeyRing;
use RivetCore\Testing\EnvelopeConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedEnvelope;

/** The kit against the reference envelope (and the harness target for the envelope mutants). */
final class EnvelopeKitTest extends EnvelopeConformanceTestCase
{
    use Flaw;

    protected function make(KeyRing $ring, array $legacyReaders = []): EnvelopeInterface
    {
        return new FlawedEnvelope(self::$flaw, $ring, $legacyReaders);
    }
}
