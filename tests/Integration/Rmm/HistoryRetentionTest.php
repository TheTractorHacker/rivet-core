<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Maintenance\Housekeeping;
use RivetCore\Tests\Support\RmmTestCase;

/** Housekeeping retention of the Phase 1 tables: the check history ring, the software change log and long-removed software, in batches. */
final class HistoryRetentionTest extends RmmTestCase
{
    private int $dev = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->enable();
        $this->h->asset(['name' => 'R', 'serial' => 'RET-1']);
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 5), $this->h::device(['serial' => 'RET-1']));
        $this->dev = (int) $j['device_id'];
    }

    private function hist(string $when, int $n = 1, string $key = 'disk_c'): void
    {
        for ($i = 0; $i < $n; ++$i) {
            $this->h->q("INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES ({$this->dev}, '$key', 'ok', '', '$when')");
        }
    }

    public function testTheCheckHistoryRingKeepsTheConfiguredDaysAndPrunesTheRestInBatches(): void
    {
        $this->hist(gmdate('Y-m-d H:i:s', time() - 8 * 86400), 3);
        $this->hist(gmdate('Y-m-d H:i:s', time() - 6 * 86400), 2);
        $this->hist(gmdate('Y-m-d H:i:s', time() - 60), 1);
        $r = $this->h->module->housekeeping()->run();
        $this->assertSame(3, $r['pruned_check_history'], 'the default is seven days');
        $this->assertSame(3, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_check_history'));
        $this->h->module->settings()->update(['limits_json' => ['check_history_days' => 1]]);
        $this->assertSame(2, $this->h->module->housekeeping()->run()['pruned_check_history']);
        $this->h->module->settings()->update(['limits_json' => ['check_history_days' => 0]]);
        $this->h->module->housekeeping()->run();
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_check_history'), '0 stops recording but never deletes what is younger than a day');
    }

    public function testALargeBacklogIsDeletedInBatchesWithPausesAndACap(): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $total = Housekeeping::PRUNE_BATCH * 2 + 100;
        for ($i = 0; $i < $total; $i += 1000) {
            $values = [];
            for ($j = $i; $j < min($total, $i + 1000); ++$j) {
                $values[] = "({$this->dev}, 'k$j', 'ok', '', '$old')";
            }
            $this->h->q('INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES ' . implode(',', $values));
        }
        $this->assertSame(Housekeeping::PRUNE_BATCH * 2 + 100, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_check_history'));
        $pauses = [];
        $hk = new Housekeeping($this->h->module->sql(), $this->h->module->settings(), $this->h->bridge, $this->h->module->jobs(), static function (int $us) use (&$pauses): void {
            $pauses[] = $us;
        });
        $r = $hk->run();
        $this->assertSame(Housekeeping::PRUNE_BATCH * 2 + 100, $r['pruned_check_history']);
        $this->assertCount(2, $pauses, 'a pause after each full batch');
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_check_history'));
    }

    public function testSoftwareHistoryAndLongRemovedSoftwareExpireButCurrentSoftwareNever(): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - 400 * 86400);
        $recent = gmdate('Y-m-d H:i:s', time() - 10 * 86400);
        $d = $this->dev;
        $this->h->q("INSERT INTO rmm_software_history (device_id, software_key, name, source, change_type, occurred_at) VALUES ($d, 'k1', 'old', 'dpkg', 'removed', '$old'), ($d, 'k2', 'new', 'dpkg', 'installed', '$recent')");
        $this->h->q("INSERT INTO rmm_device_software (device_id, software_key, name, source, version, first_seen_at, last_seen_at, removed_at) VALUES
            ($d, 'k1', 'old', 'dpkg', '1', '$old', '$old', '$old'), ($d, 'k2', 'new', 'dpkg', '1', '$old', '$recent', NULL), ($d, 'k3', 'gone-recently', 'dpkg', '1', '$old', '$recent', '$recent')");
        $r = $this->h->module->housekeeping()->run();
        $this->assertSame([1, 1], [$r['pruned_software_history'], $r['pruned_software_removed']]);
        $this->assertSame(['k2', 'k3'], array_column($this->h->rows('SELECT software_key FROM rmm_device_software ORDER BY 1'), 'software_key'));
        $this->h->module->settings()->update(['limits_json' => ['software_history_days' => 5]]);
        $r = $this->h->module->housekeeping()->run();
        $this->assertSame([1, 1], [$r['pruned_software_history'], $r['pruned_software_removed']]);
        $this->assertSame(['k2'], array_column($this->h->rows('SELECT software_key FROM rmm_device_software'), 'software_key'), 'an item that is still installed is never pruned, however old');
    }

    public function testLimitsAreValidated(): void
    {
        $s = $this->h->module->settings();
        foreach (['{"check_history_days":366}', '{"check_history_days":-1}', '{"check_history_gap_s":59}', '{"check_history_gap_s":86401}', '{"software_history_days":0}', '{"software_history_days":3651}'] as $bad) {
            $this->assertNotNull($s::validateLimits($bad)[1], $bad);
        }
        $this->assertSame([], $s->update(['limits_json' => '{"check_history_days":30,"check_history_gap_s":300,"software_history_days":90}']));
        $l = $s->limits();
        $this->assertSame([30, 300, 90], [$l['check_history_days'], $l['check_history_gap_s'], $l['software_history_days']]);
    }
}
