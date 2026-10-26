// rpt_devices: one row per enrolled device (current state).
// Source: the control database (MySQL or MariaDB). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:3306", DatabaseName = "your database".
let
    Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false]),
    Rows = Source{[Schema = DatabaseName, Item = "rpt_devices"]}[Data],
    Typed = Table.TransformColumnTypes(Rows, {{"device_id", Int64.Type}, {"client_id", Int64.Type}, {"site_id", Int64.Type}, {"asset_id", Int64.Type}, {"hostname", type text}, {"os", type text}, {"os_version", type text}, {"arch", type text}, {"manufacturer", type text}, {"model", type text}, {"agent_version", type text}, {"ring", type text}, {"link_state", type text}, {"status", type text}, {"first_seen_at", type datetime}, {"last_checkin_at", type datetime}, {"last_inventory_at", type datetime}, {"retired_at", type datetime}, {"revoked_at", type datetime}, {"uptime_s", Int64.Type}, {"pending_reboot", Int64.Type}, {"is_hypervisor_host", Int64.Type}, {"patch_scan_state", type text}, {"patch_scanned_at", type datetime}, {"patch_pending_count", Int64.Type}, {"patch_critical_count", Int64.Type}, {"patch_security_count", Int64.Type}, {"patch_reboot_pending", Int64.Type}}, "en-US")
in
    Typed
