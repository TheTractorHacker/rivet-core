<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\PackageSourcePolicyInterface;
use RivetCore\Testing\PackageSourcePolicyConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedPackageSourcePolicy;

/** The kit against Core's host-list policy, which is also the harness target for the source policy mutants. */
final class PackageSourcePolicyKitTest extends PackageSourcePolicyConformanceTestCase
{
    use Flaw;

    private ?PackageSourcePolicyInterface $policy = null;

    protected function policy(): PackageSourcePolicyInterface
    {
        return $this->policy ??= new FlawedPackageSourcePolicy(self::$flaw);
    }

    protected function allowedUrl(): string
    {
        return 'https://' . FlawedPackageSourcePolicy::HOST . '/apps/setup.msi';
    }
}
