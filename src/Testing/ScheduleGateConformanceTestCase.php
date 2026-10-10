<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\ScheduleGateInterface;

/**
 * Conformance kit for {@see ScheduleGateInterface}, the hook through which maintenance windows (or any other rule) hold back a scheduled script.
 * What every gate must do: answer one of `run`, `skip` or `defer`; give the same answer to the same question (it is asked again for a deferred
 * device); never throw for a device row or schedule with only the documented members; leave what it was given untouched.
 *
 * @api
 */
abstract class ScheduleGateConformanceTestCase extends TestCase
{
    abstract protected function gate(): ScheduleGateInterface;

    /** @return array<string,mixed> */
    private static function device(int $n = 1): array
    {
        return ['device_id' => $n, 'asset_id' => 70 + $n, 'client_id' => 5, 'location_id' => 0, 'hostname' => "HOST-$n", 'os' => 'linux', 'link_state' => 'linked', 'revoked_at' => null, 'retired_at' => null];
    }

    /** @return array<string,mixed> */
    private static function schedule(): array
    {
        return ['schedule_id' => 3, 'name' => 'Nightly', 'script_id' => 9, 'script_version' => 2, 'target' => ['type' => 'client', 'id' => 5], 'kind' => 'interval', 'interval_s' => 3600,
            'cron' => '', 'jitter_s' => 0, 'overlap' => 'skip', 'expires_s' => 3600, 'timeout_s' => 300, 'enabled' => true, 'approved' => true];
    }

    public function testTheAnswerIsOneOfTheThreeDecisions(): void
    {
        foreach ([1, 2, 3] as $n) {
            $d = $this->gate()->decide(self::device($n), self::schedule(), 1_790_000_000 + $n * 60);
            $this->assertContains($d, [ScheduleGateInterface::RUN, ScheduleGateInterface::SKIP, ScheduleGateInterface::DEFER]);
        }
    }

    public function testTheSameQuestionGetsTheSameAnswer(): void
    {
        $answers = [];
        for ($i = 0; $i < 3; ++$i) {
            $answers[] = $this->gate()->decide(self::device(), self::schedule(), 1_790_000_000);
        }
        $this->assertCount(1, array_unique($answers), 'asked three times, answered ' . implode(', ', $answers));
    }

    public function testNothingIsChangedAndNothingThrowsForAnUnusualButValidSlot(): void
    {
        $device = self::device();
        $schedule = self::schedule();
        foreach ([0, 1, 1_790_000_000, PHP_INT_MAX] as $slot) {
            $this->assertContains($this->gate()->decide($device, $schedule, $slot), [ScheduleGateInterface::RUN, ScheduleGateInterface::SKIP, ScheduleGateInterface::DEFER]);
        }
        $this->assertSame(self::device(), $device);
        $this->assertSame(self::schedule(), $schedule);
    }

    public function testADeviceRowWithOnlyTheDocumentedMembersIsEnough(): void
    {
        $this->assertContains($this->gate()->decide(['device_id' => 9, 'client_id' => 0, 'location_id' => 0], ['schedule_id' => 1], 1_790_000_000),
            [ScheduleGateInterface::RUN, ScheduleGateInterface::SKIP, ScheduleGateInterface::DEFER]);
    }
}
