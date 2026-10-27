<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Panel;

use RivetCore\Panel\Migration\Migration0040PanelApi;
use RivetCore\Panel\Migration\PanelSchema;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\PanelTestCase;
use RivetCore\Tests\Support\RmmHarness;

final class PanelMigrationTest extends PanelTestCase
{
    private function hasColumn(string $t, string $c): bool
    {
        return (int) $this->p->rmm->one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'") === 1;
    }

    public function testTheMigrationIsIdempotentAndAdditive(): void
    {
        $db = new MysqliDatabase($this->p->rmm->mysqli);
        $m = new Migration0040PanelApi();
        $this->assertSame('0040_panel_api', $m->id());
        $m->up($db);
        $m->up($db);
        foreach (array_keys(PanelSchema::columns()) as $key) {
            [$t, $c] = explode('.', $key);
            $this->assertTrue($this->hasColumn($t, $c), $key);
        }
        foreach (array_keys(PanelSchema::tables()) as $t) {
            $this->assertSame(1, (int) $this->p->rmm->one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'"));
        }
    }

    public function testAnInstallThatPredatesTheMigrationUpgradesWithItsRowsAsAgents(): void
    {
        $this->p->rmm->enable();
        [, , $agent] = $this->p->rmm->enroll($this->p->rmm->token(), RmmHarness::device());
        $this->assertSame(201, $this->p->rmm->enroll($this->p->rmm->token(), RmmHarness::device())[0]);
        $mysqli = $this->p->rmm->mysqli;
        foreach (['panel_ticket_owner', 'panel_client_settings'] as $t) {
            $mysqli->query("DROP TABLE `$t`");
        }
        foreach (['endpoint_agent_devices', 'endpoint_agent_enrollment_tokens'] as $t) {
            $mysqli->query("ALTER TABLE `$t` DROP COLUMN `kind`, DROP COLUMN `contact_id`");
        }
        // the RMM agent still works on the old schema (the kind check tolerates a missing column)
        $this->assertSame(200, $this->p->rmm->checkin($agent['device_token'])[0]);
        (new Migration0040PanelApi())->up(new MysqliDatabase($mysqli));
        $this->assertSame(['agent', 'agent'], array_column($this->p->rmm->rows('SELECT kind FROM endpoint_agent_devices'), 'kind'));
        $this->assertSame(['agent'], array_values(array_unique(array_column($this->p->rmm->rows('SELECT kind FROM endpoint_agent_enrollment_tokens'), 'kind'))));
        $this->assertSame([null, null], array_column($this->p->rmm->rows('SELECT contact_id FROM endpoint_agent_devices'), 'contact_id'));
        $this->assertSame(200, $this->p->rmm->checkin($agent['device_token'])[0]);
        $this->assertSame(401, $this->p->call('GET', 'panel_me', null, $agent['device_token'])[0]);
        $this->assertSame(201, $this->p->enroll($this->p->mint()['token'])[0]);
    }

    public function testTheColumnsDefaultToAgentAndNull(): void
    {
        $rows = $this->p->rmm->rows("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('kind','contact_id') AND TABLE_NAME IN ('endpoint_agent_devices','endpoint_agent_enrollment_tokens') ORDER BY TABLE_NAME, COLUMN_NAME");
        $this->assertCount(4, $rows);
        foreach ($rows as $r) {
            if ($r['COLUMN_NAME'] === 'kind') {
                // MariaDB reports a string default quoted ('agent'), MySQL unquoted (agent).
                $this->assertSame(['agent', 'NO'], [trim((string) $r['COLUMN_DEFAULT'], "'"), $r['IS_NULLABLE']]);
            } else {
                $this->assertSame('YES', $r['IS_NULLABLE']);
                $this->assertContains($r['COLUMN_DEFAULT'], [null, 'NULL']);
            }
        }
    }

    public function testTheOwnershipTableEnforcesOneTicketOneOwnerAndOneRequestIdPerDevice(): void
    {
        $q = $this->p->rmm->mysqli;
        $q->query('INSERT INTO panel_ticket_owner (ticket_id, device_id, client_id, request_id) VALUES (1, 1, 1, \'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa\')');
        $this->expectException(\mysqli_sql_exception::class);
        $q->query('INSERT INTO panel_ticket_owner (ticket_id, device_id, client_id) VALUES (1, 2, 1)');
    }

    public function testThePerDeviceRequestIdIsUniqueButNullsAreNot(): void
    {
        $q = $this->p->rmm->mysqli;
        $q->query('INSERT INTO panel_ticket_owner (ticket_id, device_id, client_id) VALUES (1, 1, 1), (2, 1, 1)');
        $q->query('INSERT INTO panel_ticket_owner (ticket_id, device_id, client_id, request_id) VALUES (3, 1, 1, \'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa\')');
        $q->query('INSERT INTO panel_ticket_owner (ticket_id, device_id, client_id, request_id) VALUES (4, 2, 1, \'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa\')');
        $this->assertSame(4, (int) $this->p->rmm->one('SELECT COUNT(*) FROM panel_ticket_owner'));
        $this->expectException(\mysqli_sql_exception::class);
        $q->query('INSERT INTO panel_ticket_owner (ticket_id, device_id, client_id, request_id) VALUES (5, 1, 1, \'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa\')');
    }

    public function testAClaimWithoutATicketIsAllowedOncePerDeviceAndRequestId(): void
    {
        $q = $this->p->rmm->mysqli;
        $q->query('INSERT INTO panel_ticket_owner (ticket_id, device_id, client_id, request_id) VALUES (NULL, 1, 1, \'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa\'), (NULL, 1, 1, \'bbbbbbbb-aaaa-4aaa-8aaa-aaaaaaaaaaaa\'), (NULL, 2, 1, \'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa\')');
        $this->assertSame(3, (int) $this->p->rmm->one('SELECT COUNT(*) FROM panel_ticket_owner WHERE ticket_id IS NULL'));
        $this->expectException(\mysqli_sql_exception::class);
        $q->query('INSERT INTO panel_ticket_owner (ticket_id, device_id, client_id, request_id) VALUES (NULL, 1, 1, \'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa\')');
    }
}
