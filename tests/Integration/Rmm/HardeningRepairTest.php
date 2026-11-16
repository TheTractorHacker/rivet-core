<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Migration\Migration0055HardeningRepair;
use RivetCore\Tests\Support\RmmTestCase;

/** CRR-12: a database that applied an interim head (the switches as columns of endpoint_agent_settings) is repaired, and the repair is idempotent. */
final class HardeningRepairTest extends RmmTestCase
{
    public function testADatabaseFromAnInterimHeadGetsTheTableItsValuesAndLosesTheColumns(): void
    {
        $this->h->q('DROP TABLE rmm_hardening_settings');
        $this->h->q('ALTER TABLE endpoint_agent_settings ADD COLUMN mesh_token_ttl_exact tinyint(1) NOT NULL DEFAULT 0, ADD COLUMN signed_config tinyint(1) NOT NULL DEFAULT 0, ADD COLUMN release_rollover_json text DEFAULT NULL');
        $this->h->q("UPDATE endpoint_agent_settings SET mesh_token_ttl_exact = 1, signed_config = 1, release_rollover_json = '{\"kind\":\"release_key_rollover\"}' WHERE id = 1");
        (new Migration0055HardeningRepair())->up($this->h->db);
        $this->assertSame('1|1|{"kind":"release_key_rollover"}', $this->h->one('SELECT CONCAT(mesh_token_ttl_exact, "|", signed_config, "|", release_rollover_json) FROM rmm_hardening_settings WHERE id = 1'));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endpoint_agent_settings' AND COLUMN_NAME IN ('mesh_token_ttl_exact','signed_config','release_rollover_json')"));
        // the settings read the repaired values
        $this->assertSame([1, 1], [(int) $this->h->module->settings()->get(true)['mesh_token_ttl_exact'], (int) $this->h->module->settings()->get()['signed_config']]);
        // again: nothing changes, nothing fails
        (new Migration0055HardeningRepair())->up($this->h->db);
        $this->assertSame('1', (string) $this->h->one('SELECT signed_config FROM rmm_hardening_settings WHERE id = 1'));
    }

    public function testACleanDatabaseOnlyKeepsItsTableAndRow(): void
    {
        (new Migration0055HardeningRepair())->up($this->h->db);
        $this->assertSame('0|0', $this->h->one('SELECT CONCAT(mesh_token_ttl_exact, "|", signed_config) FROM rmm_hardening_settings WHERE id = 1'));
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hardening_settings'));
    }
}
