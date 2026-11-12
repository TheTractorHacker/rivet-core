<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Device\DeviceService;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * DeviceService::transfer() moves EVERYTHING of the device that carries its own client_id (rc.59), leaves the credential and the agent link alone so the machine
 * keeps checking in, and is a no-op for the client the device is already in.
 */
final class DeviceTransferCompletenessTest extends RmmTestCase
{
    /** Tables with a device_id and a client_id that a transfer rewrites in full. Kept in step with DeviceService::CLIENT_CARRYING_TABLES by testEveryClientCarryingTableIsHandled(). */
    private const MOVED = [
        'endpoint_agent_jobs', 'endpoint_agent_transfers', 'rmm_deploy_state', 'rmm_device_apps', 'rmm_package_installs', 'rmm_package_jobs', 'rmm_package_history',
        'rmm_patch_available', 'rmm_patch_jobs', 'rmm_patch_history', 'rmm_patch_scans',
    ];
    /** Tables with device_id + client_id that are deliberately not rewritten by the SQL in DeviceService::transfer() (and why). */
    private const OTHER = [
        'rmm_alert_meta' => 'open rows only (own statement); resolved ones keep their client',
        'endpoint_agent_log_events' => 'hot store, moved by ClientMoves',
        'endpoint_agent_change_records' => 'hot store, moved by ClientMoves',
        'endpoint_agent_change_state' => 'hot store, moved by ClientMoves',
        'rmm_pending_client_moves' => 'the retry queue of ClientMoves itself',
        'panel_ticket_owner' => 'a ticket record: the ticket stays with the client it was raised for',
        'rmm_background_sessions' => 'Background Mode session history (an audit record): it keeps the client the session was launched for',
        'rmm_background_tokens' => 'single-use check tokens of those sessions: they expire in 60 seconds and are pruned with the session',
    ];

    private int $dev = 0;
    private int $asset = 0;
    private string $T = '';
    private int $seq = 0;

    protected function makeHarness(): RmmHarness
    {
        $all = [RmmAbility::DEVICE_VIEW, RmmAbility::DEVICE_MANAGE, RmmAbility::JOB_RUN_SAVED];

        return new RmmHarness(policy: new AllowUsersPolicy([1 => true, 20 => $all, 21 => $all]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->enable();
        $this->asset = $this->h->asset(['name' => 'Mover', 'serial' => 'MV-1']);
        [$c, , $j] = $this->h->enroll($this->h->token(null, 24, 10), $this->h::device(['serial' => 'MV-1', 'hostname' => 'MOVER']));
        $this->assertSame(201, $c);
        $this->dev = (int) $j['device_id'];
        $this->T = $j['device_token'];
    }

    private function svc(): DeviceService
    {
        return $this->h->module->deviceService();
    }

    /** Insert one row into $table for $device, filling every other required column with a dummy of the right type. */
    private function seed(string $table, int $device, int $client, array $over = []): void
    {
        $vals = ['device_id' => $device, 'client_id' => $client] + $over;
        $cols = $this->h->rows("SELECT COLUMN_NAME n, DATA_TYPE t, IS_NULLABLE nul, COLUMN_DEFAULT d, EXTRA x, CHARACTER_MAXIMUM_LENGTH len, COLUMN_TYPE ct FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'");
        $has = array_column($cols, 'n');
        if (!in_array('client_id', $has, true)) {
            unset($vals['client_id']);
        }
        foreach ($cols as $c) {
            $n = $c['n'];
            if (array_key_exists($n, $vals) || $c['nul'] === 'YES' || $c['d'] !== null || str_contains((string) $c['x'], 'auto_increment')) {
                continue;
            }
            ++$this->seq;
            $vals[$n] = match (true) {
                in_array($c['t'], ['int', 'bigint', 'smallint', 'tinyint', 'mediumint'], true) => $this->seq,
                in_array($c['t'], ['datetime', 'timestamp'], true) => gmdate('Y-m-d H:i:s'),
                $c['t'] === 'date' => gmdate('Y-m-d'),
                $c['t'] === 'enum' => (string) explode("'", (string) $c['ct'])[1],
                in_array($c['t'], ['decimal', 'float', 'double'], true) => 0,
                default => substr('s' . $this->seq . 'x' . bin2hex(random_bytes(6)), 0, max(1, min(30, (int) ($c['len'] ?: 30)))),
            };
        }
        $mysqli = $this->h->mysqli;
        $names = implode(',', array_map(static fn (string $k): string => "`$k`", array_keys($vals)));
        $lits = implode(',', array_map(static fn ($v): string => is_int($v) ? (string) $v : "'" . addslashes((string) $v) . "'", $vals));
        $mysqli->query("INSERT INTO `$table` ($names) VALUES ($lits)");
    }

    private function clientOf(string $table, int $device, string $where = '1'): array
    {
        return array_map('intval', array_column($this->h->rows("SELECT client_id FROM `$table` WHERE device_id = $device AND $where"), 'client_id'));
    }

    public function testEveryClientCarryingTableIsHandled(): void
    {
        $listed = (new \ReflectionClass(DeviceService::class))->getReflectionConstant('CLIENT_CARRYING_TABLES')->getValue();
        $this->assertEqualsCanonicalizing(self::MOVED, $listed);
        $found = array_column($this->h->rows("SELECT DISTINCT a.TABLE_NAME n FROM information_schema.COLUMNS a JOIN information_schema.COLUMNS b ON a.TABLE_SCHEMA = b.TABLE_SCHEMA AND a.TABLE_NAME = b.TABLE_NAME JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = a.TABLE_SCHEMA AND t.TABLE_NAME = a.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
            WHERE a.TABLE_SCHEMA = DATABASE() AND a.COLUMN_NAME = 'device_id' AND b.COLUMN_NAME = 'client_id' AND a.TABLE_NAME <> 'endpoint_agent_devices'"), 'n');
        $expected = array_merge(self::MOVED, array_keys(self::OTHER));
        $this->assertEqualsCanonicalizing($expected, $found, 'a table with device_id and client_id appeared (or went away): decide whether a device transfer must move it, then update DeviceService::CLIENT_CARRYING_TABLES and this test');
    }

    public function testATransferMovesEveryClientCarryingTableAndLeavesTheRestAlone(): void
    {
        $A = $this->h->clientA;
        $B = $this->h->clientB;
        $other = $this->h::device(['serial' => 'MV-2', 'hostname' => 'STAYS']);
        [, , $j2] = $this->h->enroll($this->h->token(null, 24, 10), $other);
        $stay = (int) $j2['device_id'];
        foreach (self::MOVED as $table) {
            if ($table === 'endpoint_agent_jobs') {
                continue;
            }
            $this->seed($table, $this->dev, $A);
            if (!in_array($table, ['rmm_patch_scans', 'rmm_deploy_state'], true)) {   // one row per device
                $this->seed($table, $this->dev, $A);
            }
            $this->seed($table, $stay, $A);
        }
        $this->seed('rmm_alert_meta', $this->dev, $A, ['state' => 'open']);
        $this->seed('rmm_alert_meta', $this->dev, $A, ['state' => 'resolved', 'resolved_at' => gmdate('Y-m-d H:i:s')]);
        $this->seed('rmm_alert_meta', $stay, $A, ['state' => 'open']);
        $this->seed('panel_ticket_owner', $this->dev, $A);
        $this->seed('endpoint_agent_jobs', $this->dev, $A, ['job_id' => $this->h::uuid(), 'state' => 'queued', 'type' => 'collect']);
        $this->seed('endpoint_agent_jobs', $this->dev, $A, ['job_id' => $this->h::uuid(), 'state' => 'succeeded', 'type' => 'collect']);

        $this->assertTrue($this->svc()->transfer($this->dev, $B, 0, 1));

        foreach (self::MOVED as $table) {
            $rows = $this->clientOf($table, $this->dev);
            $this->assertNotSame([], $rows, "$table has seeded rows");
            $this->assertSame([], array_values(array_filter($rows, static fn (int $c): bool => $c !== $B)), "$table follows the device");
            if ($table !== 'endpoint_agent_jobs') {
                $this->assertSame([$A], array_values(array_unique($this->clientOf($table, $stay))), "$table: another device is untouched");
            }
        }
        $this->assertSame([$B], $this->clientOf('rmm_alert_meta', $this->dev, "state = 'open'"), 'open alert meta follows');
        $this->assertSame([$A], $this->clientOf('rmm_alert_meta', $this->dev, "state = 'resolved'"), 'a resolved alert keeps the client it was raised under');
        $this->assertSame([$A], $this->clientOf('rmm_alert_meta', $stay), 'another device is untouched');
        $this->assertSame([$A], $this->clientOf('panel_ticket_owner', $this->dev), 'a ticket record stays with its client');
        $this->assertSame([$B, $B], $this->clientOf('endpoint_agent_jobs', $this->dev, "state IN ('queued','succeeded')"), 'queued and finished jobs both follow');
    }

    public function testDeviceOnlyTablesFollowThroughTheDeviceRow(): void
    {
        foreach (['endpoint_agent_checkins', 'rmm_device_software', 'rmm_device_state', 'rmm_software_history', 'rmm_hypervisor_hosts', 'rmm_job_extra'] as $t) {
            $cols = array_column($this->h->rows("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'"), 'c');
            $this->assertNotContains('client_id', $cols, "$t has no client column of its own, so it is scoped by the device");
        }
        $this->seed('rmm_device_software', $this->dev, 0);
        $this->assertTrue($this->svc()->transfer($this->dev, $this->h->clientB, 0, 1));
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM rmm_device_software s JOIN endpoint_agent_devices d ON d.device_id = s.device_id WHERE d.device_id = {$this->dev} AND d.client_id = {$this->h->clientB}"));
    }

    public function testCredentialInstallIdAndAgentLinkSurviveSoTheMachineKeepsCheckingIn(): void
    {
        $this->h->checkin($this->T);
        $before = $this->h->rows("SELECT token_hash, install_id, link_state, asset_id, token_expires_at, revoked_at FROM endpoint_agent_devices WHERE device_id = {$this->dev}")[0];
        $this->assertNotSame('', $before['token_hash']);
        $this->assertTrue($this->svc()->transfer($this->dev, $this->h->clientB, 0, 1));
        $after = $this->h->rows("SELECT token_hash, install_id, link_state, asset_id, token_expires_at, revoked_at FROM endpoint_agent_devices WHERE device_id = {$this->dev}")[0];
        $this->assertSame($before, $after);
        $this->assertSame(200, $this->h->checkin($this->T)[0], 'the same device token still authenticates');
        $this->assertSame($this->h->clientB, (int) $this->h->one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = {$this->dev}"), 'a check-in does not move it back');
        $this->assertGreaterThanOrEqual(2, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id = {$this->dev}"));
    }

    public function testMovingToTheClientItAlreadyBelongsToIsANoOpWithoutAnAuditRow(): void
    {
        $this->seed('rmm_patch_available', $this->dev, $this->h->clientA);
        $audit = count($this->h->audit->records());
        $this->assertTrue($this->svc()->transfer($this->dev, $this->h->clientA, 0, 1));
        $this->assertSame($audit, count($this->h->audit->records()), 'no audit row');
        $this->assertSame($this->h->clientA, $this->h->assets->row($this->asset)['client_id']);
        $this->assertTrue($this->svc()->transfer($this->dev, $this->h->clientB, 0, 1));
        $this->assertSame($audit + 1, count($this->h->audit->records()));
        $this->assertTrue($this->svc()->transfer($this->dev, $this->h->clientB, 0, 1), 'repeating the move is harmless');
        $this->assertSame($audit + 1, count($this->h->audit->records()));
    }

    public function testARetiredDeviceCanBeMovedAndStaysRetired(): void
    {
        $this->assertTrue($this->svc()->retire($this->dev, 1));
        $this->seed('rmm_patch_history', $this->dev, $this->h->clientA);
        $this->assertTrue($this->svc()->transfer($this->dev, $this->h->clientB, 0, 1));
        $this->assertSame([$this->h->clientB], $this->clientOf('rmm_patch_history', $this->dev));
        $this->assertNotNull($this->h->one("SELECT retired_at FROM endpoint_agent_devices WHERE device_id = {$this->dev}"));
    }

    public function testTechniciansOfTheOldClientLoseAndTheNewClientGainTheDeviceItsJobsPatchesAndSoftware(): void
    {
        $this->h->tenancy->restrictUser(20, [$this->h->clientA]);
        $this->h->tenancy->restrictUser(21, [$this->h->clientB]);
        $oldTech = new RmmPrincipal(20, 'OldTech');
        $newTech = new RmmPrincipal(21, 'NewTech');
        $t = $this->h->module->technician();
        $jobId = $this->h::uuid();
        $this->seed('endpoint_agent_jobs', $this->dev, $this->h->clientA, ['job_id' => $jobId, 'asset_id' => $this->asset, 'type' => 'collect', 'state' => 'queued']);
        $this->seed('rmm_patch_available', $this->dev, $this->h->clientA, ['status' => 'available']);
        $this->seed('rmm_device_software', $this->dev, 0);
        $reader = $this->h->module->patchReader();
        $oldIds = $this->h->tenancy->visibleClientIds(20);
        $newIds = $this->h->tenancy->visibleClientIds(21);

        $this->assertNotNull($t->visibleDevice(20, $this->dev));
        $this->assertNull($t->visibleDevice(21, $this->dev));
        $this->assertSame(1, $reader->fleetUpdates($oldIds, null)['total']);
        $this->assertSame(0, $reader->fleetUpdates($newIds, null)['total']);

        $this->assertTrue($t->transfer($this->h->principal(1, 'Admin'), $this->dev, $this->h->clientB)->ok);

        $this->assertNull($t->visibleDevice(20, $this->dev), 'the old client can no longer see the device');
        $this->assertNotNull($t->visibleDevice(21, $this->dev));
        $this->assertSame(0, $reader->fleetUpdates($oldIds, null)['total'], 'nor its patch rows');
        $this->assertSame(1, $reader->fleetUpdates($newIds, null)['total']);
        $this->assertSame(404, $t->cancelJob($oldTech, $this->dev, $jobId)->http, 'nor its jobs (same 404 as a missing device)');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE job_id = '$jobId' AND client_id IN (" . implode(',', $oldIds ?? [0]) . ')'));
        // software has no client column: it is visible exactly to the users who can see the device row
        $soft = static fn (array $ids, \RivetCore\Tests\Support\RmmHarness $h, int $dev): int => (int) $h->one("SELECT COUNT(*) FROM rmm_device_software s JOIN endpoint_agent_devices d ON d.device_id = s.device_id WHERE s.device_id = $dev AND d.client_id IN (" . implode(',', $ids) . ')');
        $this->assertSame(0, $soft($oldIds ?? [0], $this->h, $this->dev));
        $this->assertSame(1, $soft($newIds ?? [0], $this->h, $this->dev));
        $this->assertTrue($t->cancelJob($newTech, $this->dev, $jobId)->ok, 'the new client can act on the queued job that followed');
    }
}
