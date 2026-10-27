// rpt_hypervisor_vms: one row per virtual machine (current state).
// Source: the control database (MySQL or MariaDB). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:3306", DatabaseName = "your database".
let
    Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false]),
    Rows = Source{[Schema = DatabaseName, Item = "rpt_hypervisor_vms"]}[Data],
    Typed = Table.TransformColumnTypes(Rows, {{"vm_id", Int64.Type}, {"host_device_id", Int64.Type}, {"client_id", Int64.Type}, {"vm_name", type text}, {"vm_type", type text}, {"state", type text}, {"state_changed_at", type datetime}, {"node", type text}, {"cpus", Int64.Type}, {"cpu_pct", type number}, {"mem_bytes", Int64.Type}, {"mem_max_bytes", Int64.Type}, {"disk_bytes", Int64.Type}, {"disk_used_bytes", Int64.Type}, {"uptime_s", Int64.Type}, {"is_template", Int64.Type}, {"snapshot_count", Int64.Type}, {"oldest_snapshot_at", type datetime}, {"last_backup_at", type datetime}, {"last_backup_result", type text}, {"backup_covered", Int64.Type}, {"guest_agent", Int64.Type}, {"replication", type text}, {"ignored", Int64.Type}, {"first_seen_at", type datetime}, {"last_seen_at", type datetime}, {"missing_since", type datetime}}, "en-US")
in
    Typed
