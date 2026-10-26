// rpt_check_status: one row per device and check (current state).
// Source: the control database (MySQL or MariaDB). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:3306", DatabaseName = "your database".
let
    Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false]),
    Rows = Source{[Schema = DatabaseName, Item = "rpt_check_status"]}[Data],
    Typed = Table.TransformColumnTypes(Rows, {{"device_id", Int64.Type}, {"client_id", Int64.Type}, {"check_name", type text}, {"status", type text}, {"tier", type text}, {"flapping", Int64.Type}, {"consecutive_failures", Int64.Type}, {"consecutive_ok", Int64.Type}, {"episode", Int64.Type}, {"last_reported_at", type datetime}, {"last_changed_at", type datetime}}, "en-US")
in
    Typed
