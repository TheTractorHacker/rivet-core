// rpt_patch_updates: one row per device and update that is or was available.
// Source: the control database (MySQL or MariaDB). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:3306", DatabaseName = "your database".
let
    Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false]),
    Rows = Source{[Schema = DatabaseName, Item = "rpt_patch_updates"]}[Data],
    Typed = Table.TransformColumnTypes(Rows, {{"patch_row_id", Int64.Type}, {"device_id", Int64.Type}, {"client_id", Int64.Type}, {"update_id", type text}, {"kb", type text}, {"title", type text}, {"kind", type text}, {"severity", type text}, {"status", type text}, {"reboot_required", Int64.Type}, {"size_bytes", Int64.Type}, {"released_at", type datetime}, {"first_seen_at", type datetime}, {"last_seen_at", type datetime}, {"status_at", type datetime}, {"fail_count", Int64.Type}}, "en-US")
in
    Typed
