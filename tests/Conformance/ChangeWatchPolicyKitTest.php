<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\ChangeWatchPolicyInterface;
use RivetCore\Testing\ChangeWatchPolicyConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedChangeWatchPolicy;

/** The kit against Core's default policy, which is also the harness target for the watch policy mutants. */
final class ChangeWatchPolicyKitTest extends ChangeWatchPolicyConformanceTestCase
{
    use Flaw;

    private ?ChangeWatchPolicyInterface $policy = null;

    protected function policy(): ChangeWatchPolicyInterface
    {
        return $this->policy ??= new FlawedChangeWatchPolicy(self::$flaw);
    }

    protected function allowedRegistryKey(): string
    {
        return 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run';
    }

    protected function allowedFilePath(): string
    {
        return '/etc/ssh/sshd_config';
    }
}
