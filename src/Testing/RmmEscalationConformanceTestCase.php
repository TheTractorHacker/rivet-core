<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\RmmEscalationInterface;

/**
 * Conformance kit for {@see RmmEscalationInterface}. The interface is optional, so what is checked everywhere is the safety rule: none of
 * the three methods throws for a well-formed call, an unknown alert is a quiet no-op, repeated calls are harmless, and `notify()` answers
 * honestly (false when nothing was delivered). An adapter that can be observed also tells the case what it received
 * ({@see self::notifications()}, null when it keeps nothing) and then the notification must arrive complete, in order, with its targets intact.
 *
 * @api
 */
abstract class RmmEscalationConformanceTestCase extends TestCase
{
    abstract protected function escalation(): RmmEscalationInterface;

    /** True when this adapter delivers notifications (the null adapter does not, and must then answer false). */
    protected function delivers(): bool
    {
        return true;
    }

    /**
     * What the adapter received so far, oldest first, or null when it keeps nothing.
     *
     * @return list<array<string,mixed>>|null
     */
    protected function notifications(): ?array
    {
        return null;
    }

    /** @return array<string,mixed> */
    private function note(int $n, bool $repeat = false): array
    {
        return ['alert_id' => 500 + $n, 'device_id' => 10 + $n, 'asset_id' => $n % 2 === 0 ? null : 77, 'client_id' => 5, 'hostname' => "HOST-$n", 'check_key' => 'disk_c',
            'severity' => $n % 2 === 0 ? 'error' : 'warning', 'message' => "Endpoint agent check 'disk_c' failed on HOST-$n", 'state' => 'open', 'step' => 1 + $n, 'repeat' => $repeat,
            'policy_id' => 3, 'policy_name' => 'On call', 'targets' => [['type' => 'user', 'ref' => '12'], ['type' => 'email', 'ref' => 'noc@example.test'], ['type' => 'chat', 'ref' => '#ops']],
            'opened_at' => '2026-10-10T12:00:00Z', 'notified_at' => '2026-10-10T12:05:00Z'];
    }

    public function testNotifyNeverThrowsAndAnswersHonestly(): void
    {
        $r = $this->escalation()->notify(1, $this->note(1));
        $this->assertSame($this->delivers(), $r, $this->delivers() ? 'a delivering adapter answers true' : 'an adapter that delivers nothing answers false');
    }

    public function testRepeatsAndSeverityVariantsAreAccepted(): void
    {
        $e = $this->escalation();
        foreach ([0, 1, 2, 3] as $n) {
            $e->notify(1, $this->note($n, $n === 3));
        }
        $this->addToAssertionCount(1);
    }

    public function testAcknowledgeAndSeverityForAnUnknownAlertAreQuiet(): void
    {
        $e = $this->escalation();
        $e->acknowledgeAlert(1, 987654321, 3);
        $e->acknowledgeAlert(1, 987654321, 3);
        $e->raiseAlertSeverity(1, 987654321, 'error');
        $e->raiseAlertSeverity(1, 987654321, 'warning');
        $this->addToAssertionCount(1);
    }

    public function testNotificationsArriveCompleteAndInOrder(): void
    {
        $e = $this->escalation();
        $e->notify(1, $this->note(1));
        $e->notify(1, $this->note(2, true));
        $got = $this->notifications();
        if ($got === null) {
            $this->markTestSkipped('This adapter does not keep notifications.');
        }
        $this->assertCount(2, $got);
        $this->assertEquals($this->note(1), $got[0]);
        $this->assertEquals($this->note(2, true), $got[1]);
    }

    public function testTargetsKeepTheirOrderAndNullsSurvive(): void
    {
        $this->escalation()->notify(1, $this->note(2));
        $got = $this->notifications();
        if ($got === null) {
            $this->markTestSkipped('This adapter does not keep notifications.');
        }
        $last = $got[count($got) - 1];
        $this->assertSame(['user', 'email', 'chat'], array_column($last['targets'], 'type'));
        $this->assertArrayHasKey('asset_id', $last);
        $this->assertNull($last['asset_id']);
        $this->assertFalse($last['repeat']);
    }
}
