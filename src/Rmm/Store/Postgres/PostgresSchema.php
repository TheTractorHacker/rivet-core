<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

/**
 * The PostgreSQL schema of the HOT RMM tables (phase 2 of #89): the twelve tables of docs/design/rmm-data-store.md section 4 with the same names and columns as
 * MariaDB, plus the rollup watermark (`rmm_metric_rollup_state`) and the denormalised `client_id` of the event tables.
 *
 * Every statement is idempotent (`IF NOT EXISTS`). Types: `bigint` surrogate ids (identity columns; a sequence on the partitioned table), `timestamptz` for every
 * time (the session is UTC; the stores read times back as `Y-m-d H:i:s` UTC through `to_char`), `double precision`, `bytea` for the dedupe digest, `text` for the
 * agent's status block (it is returned byte for byte, `jsonb` would reformat it) and `jsonb` for the rollup note.
 *
 * Two storage modes, chosen once when the schema is first created ({@see PostgresMigrator}) and recorded in `rivet_core_pg_meta`:
 *  - {@see MODE_NATIVE}: the three big time series (`rmm_metric_hourly`, `rmm_metric_daily`, `endpoint_agent_check_history`) are declarative RANGE partitioned
 *    tables whose only initial partition is the DEFAULT one, so an install that never enables partitioning behaves like a plain table; the maintenance creates and
 *    drops the day partitions when the `partitioning` limit is on. Every primary key contains the partition column.
 *  - {@see MODE_TIMESCALE}: the same three are plain tables turned into TimescaleDB hypertables (one day chunks). Needs the extension to be installed by the
 *    database owner (`CREATE EXTENSION timescaledb`); Core detects it, never creates it, and never requires it.
 *
 * Index choice follows the access pattern: B-tree for the lookups the pages make (device + time, key + time) and for uniqueness; BRIN on the time column of the
 * big append-ordered tables, where the only time access is the retention range delete and a B-tree would cost a write on every insert.
 *
 * @internal
 */
final class PostgresSchema
{
    public const MODE_NATIVE = 'native';
    public const MODE_TIMESCALE = 'timescale';

    /** Tables created as partitioned parents (native) or hypertables (timescale): name => partition column. */
    public const TIME_SERIES = [
        'rmm_metric_hourly' => 'hour_start',
        'rmm_metric_daily' => 'day_start',
        'endpoint_agent_check_history' => 'reported_at',
    ];

    /** Every table of the hot store, in creation order. */
    public const TABLES = [
        'rmm_metric_latest', 'rmm_metric_hourly', 'rmm_metric_daily', 'rmm_metric_rollup_state', 'endpoint_agent_check_history', 'endpoint_agent_log_events', 'endpoint_agent_log_state',
        'endpoint_agent_change_records', 'endpoint_agent_change_state', 'rmm_software_history', 'rmm_patch_history', 'rmm_package_history', 'rmm_hypervisor_vm_history',
    ];

    /**
     * The statements of the first schema version, in order.
     *
     * @return list<string>
     */
    public static function statements(string $mode): array
    {
        if ($mode !== self::MODE_NATIVE && $mode !== self::MODE_TIMESCALE) {
            throw new \InvalidArgumentException('Unknown Postgres storage mode "' . $mode . '".');
        }
        $native = $mode === self::MODE_NATIVE;
        $by = static fn (string $col): string => $native ? " PARTITION BY RANGE ($col)" : '';
        $s = [];

        // ---- metrics
        $s[] = 'CREATE TABLE IF NOT EXISTS rmm_metric_latest (
  asset_id integer NOT NULL,
  metric_key varchar(64) NOT NULL,
  instance varchar(64) NOT NULL DEFAULT \'\',
  value double precision NOT NULL,
  label varchar(64),
  sampled_at timestamptz NOT NULL,
  PRIMARY KEY (asset_id, metric_key, instance)
)';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_metric_latest_sampled ON rmm_metric_latest (sampled_at)';
        foreach (['rmm_metric_hourly' => 'hour_start', 'rmm_metric_daily' => 'day_start'] as $table => $col) {
            $s[] = "CREATE TABLE IF NOT EXISTS $table (
  asset_id integer NOT NULL,
  metric_key varchar(64) NOT NULL,
  instance varchar(64) NOT NULL DEFAULT '',
  $col timestamptz NOT NULL,
  samples bigint NOT NULL DEFAULT 0,
  sum_value double precision NOT NULL DEFAULT 0,
  min_value double precision NOT NULL,
  max_value double precision NOT NULL,
  PRIMARY KEY (asset_id, metric_key, instance, $col)
)" . $by($col);
            if ($native) {
                $s[] = "CREATE TABLE IF NOT EXISTS {$table}_default PARTITION OF $table DEFAULT";
            }
            $s[] = "CREATE INDEX IF NOT EXISTS {$table}_time_brin ON $table USING brin ($col)";
        }
        $s[] = 'CREATE TABLE IF NOT EXISTS rmm_metric_rollup_state (
  name varchar(64) PRIMARY KEY,
  watermark timestamptz,
  detail jsonb,
  updated_at timestamptz NOT NULL DEFAULT now()
)';

        // ---- check history
        if ($native) {
            $s[] = 'CREATE SEQUENCE IF NOT EXISTS endpoint_agent_check_history_hist_id_seq AS bigint';
        }
        $s[] = 'CREATE TABLE IF NOT EXISTS endpoint_agent_check_history (
  hist_id bigint NOT NULL' . ($native ? " DEFAULT nextval('endpoint_agent_check_history_hist_id_seq')" : ' GENERATED ALWAYS AS IDENTITY') . ',
  device_id integer NOT NULL,
  check_key varchar(100) NOT NULL,
  status varchar(10) NOT NULL,
  detail varchar(200) NOT NULL DEFAULT \'\',
  reported_at timestamptz NOT NULL,
  PRIMARY KEY (hist_id, reported_at)
)' . $by('reported_at');
        if ($native) {
            $s[] = 'ALTER SEQUENCE endpoint_agent_check_history_hist_id_seq OWNED BY endpoint_agent_check_history.hist_id';
            $s[] = 'CREATE TABLE IF NOT EXISTS endpoint_agent_check_history_default PARTITION OF endpoint_agent_check_history DEFAULT';
        }
        $s[] = 'CREATE INDEX IF NOT EXISTS endpoint_agent_check_history_check_time ON endpoint_agent_check_history (device_id, check_key, reported_at, hist_id)';
        $s[] = 'CREATE INDEX IF NOT EXISTS endpoint_agent_check_history_time_brin ON endpoint_agent_check_history USING brin (reported_at)';

        // ---- logs
        $s[] = 'CREATE TABLE IF NOT EXISTS endpoint_agent_log_events (
  event_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  device_id integer NOT NULL,
  client_id integer,
  occurred_at timestamptz NOT NULL,
  received_at timestamptz NOT NULL,
  level smallint NOT NULL,
  source varchar(100) NOT NULL DEFAULT \'\',
  code integer,
  message varchar(1024) NOT NULL DEFAULT \'\',
  dedupe bytea NOT NULL CHECK (octet_length(dedupe) = 16)
)';
        $s[] = 'CREATE UNIQUE INDEX IF NOT EXISTS endpoint_agent_log_events_dedupe ON endpoint_agent_log_events (device_id, dedupe)';
        $s[] = 'CREATE INDEX IF NOT EXISTS endpoint_agent_log_events_device_time ON endpoint_agent_log_events (device_id, occurred_at)';
        $s[] = 'CREATE INDEX IF NOT EXISTS endpoint_agent_log_events_occurred ON endpoint_agent_log_events (occurred_at)';
        $s[] = 'CREATE TABLE IF NOT EXISTS endpoint_agent_log_state (
  device_id integer PRIMARY KEY,
  row_count integer NOT NULL DEFAULT 0,
  dropped_total bigint NOT NULL DEFAULT 0,
  last_event_at timestamptz,
  last_batch_at timestamptz,
  window_start timestamptz,
  window_count integer NOT NULL DEFAULT 0
)';

        // ---- change records
        $s[] = 'CREATE TABLE IF NOT EXISTS endpoint_agent_change_records (
  change_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  device_id integer NOT NULL,
  client_id integer,
  occurred_at timestamptz NOT NULL,
  received_at timestamptz NOT NULL,
  kind varchar(12) NOT NULL,
  watch varchar(48) NOT NULL,
  change_type smallint NOT NULL,
  target varchar(300) NOT NULL DEFAULT \'\',
  item varchar(200) NOT NULL DEFAULT \'\',
  old_value varchar(512) NOT NULL DEFAULT \'\',
  new_value varchar(512) NOT NULL DEFAULT \'\',
  summary varchar(200) NOT NULL DEFAULT \'\',
  old_hash varchar(32) NOT NULL DEFAULT \'\',
  new_hash varchar(32) NOT NULL DEFAULT \'\',
  actor varchar(120) NOT NULL DEFAULT \'\',
  process varchar(200) NOT NULL DEFAULT \'\',
  flags smallint NOT NULL DEFAULT 0,
  dedupe bytea NOT NULL CHECK (octet_length(dedupe) = 16)
)';
        $s[] = 'CREATE UNIQUE INDEX IF NOT EXISTS endpoint_agent_change_records_dedupe ON endpoint_agent_change_records (device_id, dedupe)';
        $s[] = 'CREATE INDEX IF NOT EXISTS endpoint_agent_change_records_device_time ON endpoint_agent_change_records (device_id, occurred_at)';
        $s[] = 'CREATE INDEX IF NOT EXISTS endpoint_agent_change_records_occurred ON endpoint_agent_change_records (occurred_at)';
        $s[] = 'CREATE TABLE IF NOT EXISTS endpoint_agent_change_state (
  device_id integer PRIMARY KEY,
  client_id integer,
  row_count integer NOT NULL DEFAULT 0,
  dropped_total bigint NOT NULL DEFAULT 0,
  last_change_at timestamptz,
  last_batch_at timestamptz,
  window_start timestamptz,
  window_count integer NOT NULL DEFAULT 0,
  config_version varchar(16) NOT NULL DEFAULT \'\',
  monitor_json text,
  monitor_at timestamptz
)';

        // ---- software, patch, deployment, hypervisor histories
        $s[] = 'CREATE TABLE IF NOT EXISTS rmm_software_history (
  history_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  device_id integer NOT NULL,
  software_key varchar(40) NOT NULL,
  name varchar(200) NOT NULL,
  source varchar(12) NOT NULL,
  change_type varchar(12) NOT NULL,
  old_version varchar(100),
  new_version varchar(100),
  publisher varchar(200) NOT NULL DEFAULT \'\',
  occurred_at timestamptz NOT NULL
)';
        // The inventory is committed in the control database and the history here, in two transactions: a retried append must not write the change twice.
        $s[] = 'CREATE UNIQUE INDEX IF NOT EXISTS rmm_software_history_once ON rmm_software_history (device_id, software_key, change_type, occurred_at, (coalesce(old_version, \'\')), (coalesce(new_version, \'\')))';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_software_history_device_time ON rmm_software_history (device_id, occurred_at, history_id)';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_software_history_time_brin ON rmm_software_history USING brin (occurred_at)';
        $s[] = 'CREATE TABLE IF NOT EXISTS rmm_patch_history (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  device_id integer NOT NULL,
  client_id integer NOT NULL DEFAULT 0,
  update_id varchar(120) NOT NULL,
  title varchar(300) NOT NULL DEFAULT \'\',
  action varchar(12) NOT NULL,
  result varchar(16) NOT NULL,
  code varchar(40) NOT NULL DEFAULT \'\',
  detail varchar(200) NOT NULL DEFAULT \'\',
  "at" timestamptz NOT NULL,
  job_id varchar(36),
  user_id integer
)';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_patch_history_device_at ON rmm_patch_history (device_id, "at", id)';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_patch_history_client_at ON rmm_patch_history (client_id, "at")';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_patch_history_job ON rmm_patch_history (job_id)';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_patch_history_at_brin ON rmm_patch_history USING brin ("at")';
        $s[] = 'CREATE TABLE IF NOT EXISTS rmm_package_history (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  device_id integer NOT NULL,
  client_id integer NOT NULL DEFAULT 0,
  package_id integer NOT NULL,
  version_id integer,
  action varchar(9) NOT NULL,
  mode varchar(8) NOT NULL,
  result varchar(18) NOT NULL,
  code varchar(40) NOT NULL DEFAULT \'\',
  detail varchar(200) NOT NULL DEFAULT \'\',
  version varchar(64) NOT NULL DEFAULT \'\',
  "at" timestamptz NOT NULL,
  job_id varchar(36),
  user_id integer
)';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_package_history_device_at ON rmm_package_history (device_id, id)';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_package_history_client_at ON rmm_package_history (client_id, "at")';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_package_history_package_at ON rmm_package_history (package_id, "at")';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_package_history_job ON rmm_package_history (job_id)';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_package_history_at_brin ON rmm_package_history USING brin ("at")';
        $s[] = 'CREATE TABLE IF NOT EXISTS rmm_hypervisor_vm_history (
  history_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  vm_id bigint NOT NULL,
  device_id integer NOT NULL,
  "at" timestamptz NOT NULL,
  from_state varchar(14) NOT NULL DEFAULT \'\',
  to_state varchar(14) NOT NULL DEFAULT \'\'
)';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_hypervisor_vm_history_vm ON rmm_hypervisor_vm_history (vm_id, history_id)';
        $s[] = 'CREATE INDEX IF NOT EXISTS rmm_hypervisor_vm_history_device ON rmm_hypervisor_vm_history (device_id)';

        if (!$native) {
            foreach (self::TIME_SERIES as $table => $col) {
                $s[] = "SELECT create_hypertable('$table', '$col', chunk_time_interval => INTERVAL '1 day', if_not_exists => TRUE)";
            }
        }

        return $s;
    }
}
