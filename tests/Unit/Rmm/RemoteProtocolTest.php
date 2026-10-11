<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Remote\RemoteProtocol as R;
use RivetCore\Rmm\Remote\RemoteSettings;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Settings\RmmSettings;

/**
 * The wire contract of Phases 6 and 8, pinned the way FrozenConstantsTest pins the original protocol: installed agents depend on these values once
 * released, so changing one is a protocol break (add a new job type or endpoint instead). The documents must say what the code does.
 */
final class RemoteProtocolTest extends TestCase
{
    private function doc(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testTheFrozenValues(): void
    {
        $this->assertSame(['svc_list', 'svc_control', 'proc_list', 'proc_kill', 'file_get', 'file_put'], R::JOB_TYPES);
        $this->assertSame(['remote:services', 'remote:processes', 'remote:files', 'logs:collect'], [R::CAP_SERVICES, R::CAP_PROCESSES, R::CAP_FILES, R::CAP_LOGS]);
        $this->assertSame(['remote_tools', 'filetransfer', 'logs'], [R::FEATURE_REMOTE_TOOLS, R::FEATURE_FILE_TRANSFER, R::FEATURE_LOGS]);
        $this->assertSame(['start', 'stop', 'restart', 'enable', 'disable', 'manual'], R::SERVICE_ACTIONS);
        $this->assertSame(['stop', 'restart', 'disable'], R::SERVICE_DESTRUCTIVE);
        $this->assertSame([2, 4194304, 500], [R::PID_MIN, R::PID_MAX, R::PROCESS_LIST_MAX]);
        $this->assertSame([1048576, 1000, 900, 536870912, [600, 60]], [R::TRANSFER_CHUNK_MAX, R::TRANSFER_PATH_MAX, R::TRANSFER_ALLOW_MAX, R::TRANSFER_HARD_MAX_BYTES, R::TRANSFER_RATE]);
        $this->assertSame(['awaiting_content', 'queued', 'receiving', 'delivering', 'complete', 'failed', 'expired', 'deleted'], R::TRANSFER_STATES);
        $this->assertSame([200, 1024, 262144, [30, 60], 100], [R::LOG_BATCH_MAX, R::LOG_MESSAGE_MAX, R::LOG_BODY_MAX, R::LOG_RATE, R::LOG_SOURCE_MAX]);
        $this->assertSame(['critical', 'error', 'warning', 'info', 'debug'], R::LOG_LEVELS);
        $this->assertSame('log.rule_', R::LOG_CHECK_PREFIX);
        $this->assertSame('rmm.remote.manage', RmmAbility::REMOTE_MANAGE);
        $this->assertContains('rmm.remote.manage', RmmAbility::all());
        $this->assertFalse(RmmAbility::isAdministrative(RmmAbility::REMOTE_MANAGE), 'it is a technician grant that also needs device.view');
        foreach (R::JOB_TYPES as $t) {
            $this->assertLessThanOrEqual(20, strlen($t), 'endpoint_agent_jobs.type is 20 characters');
        }
        $this->assertSame(1, R::levelNumber('critical'));
        $this->assertSame('debug', R::levelName(5));
        $this->assertNull(R::levelNumber('fatal'));
    }

    public function testTheSubSwitchesAreOffUnlessAnAdministratorTurnsThemOn(): void
    {
        foreach ([R::FEATURE_REMOTE_TOOLS, R::FEATURE_FILE_TRANSFER, R::FEATURE_LOGS] as $f) {
            $this->assertContains($f, RmmSettings::FEATURES);
            $this->assertNotContains($f, RmmSettings::LEGACY_FEATURES, "$f is not part of the legacy defaults, so an upgrade changes nothing");
        }
        $this->assertSame(['transfer_max_bytes', 'transfer_ttl_s', 'transfer_store_max_bytes', 'logs_retention_days', 'logs_device_max_rows', 'logs_fleet_max_rows', 'logs_rate_per_min', 'logs_interval_s'], array_keys(RemoteSettings::LIMITS));
        $this->assertSame([52428800, 86400, 1073741824, 7, 20000, 2000000, 600, 60], array_map(static fn (array $l): int => $l[2], array_values(RemoteSettings::LIMITS)));
        foreach (RemoteSettings::LIMITS as $k => [$min, $max, $def]) {
            $this->assertGreaterThanOrEqual($min, $def, $k);
            $this->assertLessThanOrEqual($max, $def, $k);
        }
        $this->assertStringContainsString('cannot be un-copied', RemoteSettings::FILE_TRANSFER_RISK);
        $this->assertStringContainsString('personal data', RemoteSettings::LOGS_STORAGE_WARNING);
    }

    public function testTheProtocolDocumentSaysWhatTheCodeDoes(): void
    {
        $doc = $this->doc('docs/rmm/PROTOCOL.md');
        foreach (array_merge(R::JOB_TYPES, [R::CAP_SERVICES, R::CAP_PROCESSES, R::CAP_FILES, R::CAP_LOGS, 'agent_transfer', 'agent_logs', 'X-Transfer-Token', 'X-Transfer-Last', 'X-Transfer-Sha256',
            'config.logs', 'path_denied', 'checksum_mismatch', 'refused_self', 'name_mismatch', 'no_allowed_roots']) as $needle) {
            $this->assertStringContainsString($needle, $doc, $needle);
        }
        foreach (['/etc/shadow', 'System32\\config', 'id_rsa', 'ntds.dit'] as $deny) {
            $this->assertStringContainsString($deny, $doc, "the never-transfer list names $deny");
        }
        $this->assertStringContainsString('1 MiB', $doc);
        $this->assertStringContainsString('262144', $doc);
    }

    public function testTheOpenApiDocumentCoversEveryNewRoute(): void
    {
        $yaml = $this->doc('docs/rmm/openapi-device.yaml');
        foreach (['/agent_transfer', '/agent_logs', '/endpoint_devices/{id}/services', '/endpoint_devices/{id}/services/query', '/endpoint_devices/{id}/services/{name}/{action}', '/endpoint_devices/{id}/processes',
            '/endpoint_devices/{id}/processes/query', '/endpoint_devices/{id}/processes/{pid}/kill', '/endpoint_devices/{id}/remote/mesh', '/endpoint_devices/{id}/remote/node', '/endpoint_devices/{id}/remote/jobs/{job_id}',
            '/endpoint_devices/{id}/files', '/endpoint_devices/{id}/files/download', '/endpoint_devices/{id}/files/upload', '/endpoint_devices/{id}/files/{transfer_id}', '/endpoint_devices/{id}/files/{transfer_id}/content',
            '/endpoint_devices/remote/settings', '/endpoint_devices/logs', '/endpoint_devices/logs/stats', '/endpoint_devices/logs/rules', '/endpoint_devices/logs/rules/{rule_id}', '/endpoint_devices/{id}/logs',
            '/endpoint_devices/{id}/logs/stats'] as $path) {
            $this->assertMatchesRegularExpression('#^  ' . preg_quote($path, '#') . ':$#m', $yaml, $path);
        }
        foreach (['protected', 'unsupported', 'path_denied', 'checksum_mismatch'] as $code) {
            $this->assertStringContainsString($code, $yaml);
        }
        $this->assertStringContainsString('rmm.remote.manage', $yaml);
    }

    public function testTheEventsAreInTheCatalogAndTheDocs(): void
    {
        $this->assertContains(RmmEvent::FILE_TRANSFERRED, RmmEvent::all());
        $this->assertContains(RmmEvent::LOG_RULE_MATCHED, RmmEvent::all());
        $this->assertSame('rmm.file.transferred', RmmEvent::FILE_TRANSFERRED);
        $this->assertSame('rmm.log.rule_matched', RmmEvent::LOG_RULE_MATCHED);
    }

    public function testTheMigrationIsRegisteredAndUnique(): void
    {
        $ids = array_map(static fn ($m): string => $m->id(), \RivetCore\Migration\CoreMigrations::all());
        $this->assertContains('0022_rmm_remote_and_logs', $ids);
        $this->assertContains('0023_rmm_mesh_agent', $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame(['endpoint_agent_remote_settings', 'endpoint_agent_transfers', 'endpoint_agent_log_events', 'endpoint_agent_log_state', 'endpoint_agent_log_rules'], array_keys(\RivetCore\Rmm\Migration\RemoteSchema::tables()));
        foreach (\RivetCore\Rmm\Migration\RemoteSchema::tables() as $name => $ddl) {
            $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS', $ddl, $name);
            $this->assertStringContainsString('COLLATE=utf8mb4_general_ci', $ddl, $name);
            $this->assertStringNotContainsString('ALTER', $ddl, $name);
        }
    }
}
