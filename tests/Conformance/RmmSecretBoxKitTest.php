<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Testing\SecretBoxConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedSecretBox;

/** The kit against the libsodium reference box (and the harness target for the secret-box mutants). */
final class RmmSecretBoxKitTest extends SecretBoxConformanceTestCase
{
    use Flaw;

    private ?FlawedSecretBox $box = null;

    protected function box(): SecretBoxInterface
    {
        return $this->box ??= new FlawedSecretBox(self::$flaw);
    }
}
