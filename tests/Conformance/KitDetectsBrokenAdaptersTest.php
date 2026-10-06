<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Contracts\RequestContextInterface;
use RivetCore\Contracts\SettingsInterface;
use RivetCore\Mcp\AgentDirectoryInterface;
use RivetCore\Tests\Conformance\Reference\InMemoryAgentDirectory;

/**
 * A conformance kit that nothing can fail is worthless. Each case here wires a deliberately wrong adapter into one of the
 * kit's test cases and requires that at least the expected check reports a failure.
 */
final class KitDetectsBrokenAdaptersTest extends TestCase
{
    /** @param class-string<TestCase> $case */
    private function failures(TestCase $case): array
    {
        $failed = [];
        foreach (get_class_methods($case) as $m) {
            if (!str_starts_with($m, 'test')) {
                continue;
            }
            try {
                $case->$m();
            } catch (AssertionFailedError $e) {
                if (!$e instanceof \PHPUnit\Framework\SkippedWithMessageException && !$e instanceof \PHPUnit\Framework\SkippedTest) {
                    $failed[] = $m;
                }
            }
        }

        return $failed;
    }

    public function testSettingsThatSwallowTheDefaultAreCaught(): void
    {
        $case = new class('x') extends SettingsConformanceTestCase {
            protected function settingsWith(array $values): SettingsInterface
            {
                return new class($values) implements SettingsInterface {
                    public function __construct(private array $v)
                    {
                    }

                    public function get(string $key, mixed $default = null): mixed
                    {
                        return $this->v[$key] ?? null;   // BUG: ignores $default
                    }
                };
            }
        };
        $this->assertContains('testMissingKeyReturnsNullOrTheGivenDefault', $this->failures($case));
    }

    public function testRequestContextThatTrustsAClientRequestIdIsCaught(): void
    {
        $case = new class('x') extends RequestContextConformanceTestCase {
            protected function context(): RequestContextInterface
            {
                return new class implements RequestContextInterface {
                    public function ipAddress(): ?string
                    {
                        return 'not-an-ip';
                    }

                    public function userAgent(): ?string
                    {
                        return "ua\r\nInjected: 1";
                    }

                    public function requestId(): ?string
                    {
                        return str_repeat('x', 100) . "\n";
                    }
                };
            }
        };
        $failed = $this->failures($case);
        $this->assertContains('testIpAddressIsAValidAddressWhenPresent', $failed);
        $this->assertContains('testNoValueContainsControlCharacters', $failed);
        $this->assertContains('testRequestIdFitsTheAuditColumn', $failed);
    }

    public function testAnAccessPolicyThatAllowsEverythingIsCaught(): void
    {
        $case = new class('x') extends AccessPolicyConformanceTestCase {
            protected function policy(): AccessPolicyInterface
            {
                return new \RivetCore\Support\AllowAllPolicy();
            }

            protected function unprivilegedUserId(): int
            {
                return 2;
            }
        };
        $this->assertContains('testAnUnknownAbilityIsDeniedForAnOrdinaryUser', $this->failures($case));
    }

    public function testAnAgentDirectoryWithCaseInsensitiveIdentitiesIsCaught(): void
    {
        $dir = new class extends InMemoryAgentDirectory {
            public function identityTaken(string $issuer, string $subject): bool
            {
                foreach ($this->linkedAgents() as $a) {
                    if (strtolower($a['issuer']) === strtolower($issuer) && strtolower($a['subject']) === strtolower($subject)) {
                        return true;   // BUG: the pending-identity collation problem noted in CHANGELOG 0.18.1
                    }
                }

                return false;
            }
        };
        $case = new class('x', $dir) extends AgentDirectoryConformanceTestCase {
            public function __construct(string $name, private AgentDirectoryInterface $d)
            {
                parent::__construct($name);
            }

            protected function givenAgent(int $userId, string $name, string $email, bool $active = true): void
            {
                $this->d->add($userId, $name, $email, $active);
            }

            protected function directory(): AgentDirectoryInterface
            {
                return $this->d;
            }
        };
        $this->assertContains('testIdentityIsCaseSensitive', $this->failures($case));
    }
}
