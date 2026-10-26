// rpt_patch_compliance: one row per device that has been (or should be) scanned for updates.
// Source: the control database (MySQL or MariaDB). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:3306", DatabaseName = "your database".
let
    Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false]),
    Rows = Source{[Schema = DatabaseName, Item = "rpt_patch_compliance"]}[Data],
    Typed = Table.TransformColumnTypes(Rows, {{"device_id", Int64.Type}, {"client_id", Int64.Type}, {"scan_state", type text}, {"wu_state", type text}, {"manager", type text}, {"scanned_at", type datetime}, {"last_install_at", type datetime}, {"reboot_pending", Int64.Type}, {"pending_total", Int64.Type}, {"pending_critical", Int64.Type}, {"pending_security", Int64.Type}, {"pending_optional", Int64.Type}, {"pending_driver", Int64.Type}, {"reboot_required_count", Int64.Type}, {"missing_important_count", Int64.Type}, {"oldest_missing_important_at", type datetime}, {"failed_update_count", Int64.Type}, {"compliance_status", type text}}, "en-US")
in
    Typed
